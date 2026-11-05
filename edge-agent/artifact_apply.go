package main

import (
	"bytes"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"sort"
	"strconv"
)

// rejectionReasonsJSON is the list of rejection reasons the agent may report.
// core/resources/contracts/edge-rejection-reasons.json holds the same list for
// the control plane, and tests/contract keeps the two files identical.
//
//go:embed rejection_reasons.json
var rejectionReasonsJSON []byte

const (
	reasonIncompatibleArtifact   = "incompatible_artifact"
	reasonSignatureOrChecksum    = "signature_or_checksum_invalid"
	reasonCandidateValidation    = "candidate_validation_failed"
	reasonInvalidArtifactSchema  = "invalid_artifact_schema"
	reasonArtifactRevisionReplay = "artifact_revision_replay"
	reasonArtifactUnavailable    = "artifact_unavailable"
)

// httpStatusError carries the control plane's status so a refusal that can
// never succeed is told apart from an outage worth retrying.
type httpStatusError struct {
	Status string
	Code   int
	Body   string
}

func (e *httpStatusError) Error() string { return fmt.Sprintf("%s: %s", e.Status, e.Body) }

// permanent reports a client error that repeating the same request cannot fix.
func (e *httpStatusError) permanent() bool {
	return e.Code >= 400 && e.Code < 500 && e.Code != http.StatusRequestTimeout && e.Code != http.StatusTooManyRequests
}

// candidateError marks a failure caused by the content of a candidate state,
// as opposed to the disk, the network, or the control plane. Only these
// failures may reject an artifact.
type candidateError struct{ err error }

func (e candidateError) Error() string { return e.err.Error() }
func (e candidateError) Unwrap() error { return e.err }

type manifestItem struct {
	Sequence      uint64  `json:"sequence"`
	Kind          string  `json:"kind"`
	DomainID      *uint64 `json:"domain_id"`
	Revision      uint64  `json:"revision"`
	Checksum      string  `json:"checksum"`
	Signature     string  `json:"signature"`
	SchemaVersion int     `json:"schema_version"`
	Minimum       string  `json:"minimum_agent_version"`
	Maximum       string  `json:"maximum_agent_version"`
}

type rejection struct {
	Sequence        uint64
	Reason, Details string
}

// preparedArtifact is one verified artifact ready to be folded into a state.
type preparedArtifact struct {
	Sequence  uint64
	Kind      string
	DomainKey string
	Revision  uint64
	Payload   json.RawMessage
}

// prepareArtifact checks one manifest item without touching the active state.
// A returned rejection means this domain's artifact is skipped; a returned
// error means the control plane could not be reached and the sync retries.
func (c *client) prepareArtifact(item manifestItem, cursor uint64) (preparedArtifact, *rejection, error) {
	reject := func(reason, details string) (preparedArtifact, *rejection, error) {
		logger.Warn("runtime artifact rejected; the domain keeps its previous state", "event", "runtime_candidate_rejected", "edge_id", c.id.EdgeID, "revision_id", item.Sequence, "error_code", reason)
		return preparedArtifact{}, &rejection{Sequence: item.Sequence, Reason: reason, Details: details}, nil
	}
	if item.Sequence <= cursor || item.DomainID == nil || (item.Kind != "domain" && item.Kind != "tombstone") || item.SchemaVersion != 1 || !compatible(item.Minimum, item.Maximum) {
		return reject(reasonIncompatibleArtifact, "")
	}
	var artifact struct {
		Encoded           string `json:"encoded_payload"`
		ExpiresAt         int64  `json:"expires_at"`
		DeliverySignature string `json:"delivery_signature"`
		Data              struct {
			Sequence uint64 `json:"sequence"`
		} `json:"data"`
	}
	if err := c.request("GET", "/edge/v1/config/artifacts/"+item.Checksum, nil, &artifact, true); err != nil {
		var status *httpStatusError
		if errors.As(err, &status) && status.permanent() {
			return reject(reasonArtifactUnavailable, status.Status)
		}
		return preparedArtifact{}, nil, err
	}
	payload, err := c.verifySigned(artifact.Encoded, item.Checksum, item.Signature, deliveryScope(item.Kind, *item.DomainID, item.Revision), item.Sequence, artifact.ExpiresAt, artifact.DeliverySignature)
	if err == nil && artifact.Data.Sequence != item.Sequence {
		err = errors.New("artifact sequence mismatch")
	}
	if err != nil {
		return reject(reasonSignatureOrChecksum, err.Error())
	}
	key := strconv.FormatUint(*item.DomainID, 10)
	var metadata struct {
		SchemaVersion int    `json:"schema_version"`
		DomainID      uint64 `json:"domain_id"`
		Revision      uint64 `json:"revision"`
	}
	if err := json.Unmarshal(payload, &metadata); err != nil || metadata.Revision != item.Revision ||
		(item.Kind == "domain" && (metadata.SchemaVersion != 1 || metadata.DomainID != *item.DomainID)) {
		return reject(reasonInvalidArtifactSchema, "")
	}
	if item.Kind == "domain" {
		// Compile the domain alone, so a defect in its payload is found here and
		// charged to this domain instead of to the whole batch.
		alone := state{Sequence: item.Sequence, Domains: map[string]json.RawMessage{key: payload}}
		if _, err := requiredCertificateIDs(alone); err != nil {
			return reject(reasonCandidateValidation, err.Error())
		}
		if _, _, err := compileRuntime(alone, nil); err != nil {
			return reject(reasonCandidateValidation, err.Error())
		}
	}
	return preparedArtifact{Sequence: item.Sequence, Kind: item.Kind, DomainKey: key, Revision: metadata.Revision, Payload: payload}, nil, nil
}

// foldArtifacts applies verified artifacts in order to a copy of base. An
// artifact that does not move a domain strictly forward is dropped and reported:
// every accepted change carries a greater revision, so an equal one with different content is a
// replay. An equal one with identical content was already applied, for example before a crash, and
// is accepted without change.
func foldArtifacts(base state, items []preparedArtifact) (state, []preparedArtifact, []rejection) {
	next := state{Sequence: base.Sequence, Domains: clone(base.Domains)}
	var applicable []preparedArtifact
	var rejected []rejection
	for _, item := range items {
		if previous := next.Domains[item.DomainKey]; previous != nil {
			var active struct {
				Revision uint64 `json:"revision"`
			}
			if json.Unmarshal(previous, &active) != nil || item.Revision < active.Revision || (item.Revision == active.Revision && !bytes.Equal(previous, item.Payload)) {
				rejected = append(rejected, rejection{Sequence: item.Sequence, Reason: reasonArtifactRevisionReplay})
				continue
			}
		}
		if item.Kind == "tombstone" {
			delete(next.Domains, item.DomainKey)
		} else {
			next.Domains[item.DomainKey] = item.Payload
		}
		if item.Sequence > next.Sequence {
			next.Sequence = item.Sequence
		}
		applicable = append(applicable, item)
	}
	return next, applicable, rejected
}

// applyArtifacts activates the verified artifacts and moves the cursor to
// last. When the whole batch cannot be activated because of its content, the
// batch is halved until the offending artifact stands alone; only that
// artifact is rejected and every other domain still updates. A failure that
// is not about content (disk, network) is returned and nothing is rejected.
func (c *client) applyArtifacts(base state, items []preparedArtifact, last uint64) (state, []rejection, error) {
	result, rejected, err := c.applyGroup(base, items)
	if err != nil {
		return base, nil, err
	}
	if result.Sequence != last {
		result.Sequence = last
		if err := c.activate(result); err != nil {
			return base, nil, err
		}
	}
	return result, rejected, nil
}

func (c *client) applyGroup(base state, items []preparedArtifact) (state, []rejection, error) {
	if len(items) == 0 {
		return base, nil, nil
	}
	candidate, applicable, rejected := foldArtifacts(base, items)
	if len(applicable) == 0 {
		return base, rejected, nil
	}
	err := c.activate(candidate)
	if err == nil {
		return candidate, rejected, nil
	}
	var content candidateError
	if !errors.As(err, &content) {
		return base, nil, err
	}
	if len(applicable) == 1 {
		logger.Error("runtime artifact rejected; the domain keeps its previous state", "event", "runtime_activation_failed", "edge_id", c.id.EdgeID, "revision_id", applicable[0].Sequence, "error_code", reasonCandidateValidation, "error", err.Error())
		return base, append(rejected, rejection{Sequence: applicable[0].Sequence, Reason: reasonCandidateValidation, Details: err.Error()}), nil
	}
	middle := len(applicable) / 2
	left, first, err := c.applyGroup(base, applicable[:middle])
	if err != nil {
		return base, nil, err
	}
	right, second, err := c.applyGroup(left, applicable[middle:])
	if err != nil {
		return base, nil, err
	}
	return right, append(append(rejected, first...), second...), nil
}

func sortRejections(rejections []rejection) {
	sort.Slice(rejections, func(i, j int) bool { return rejections[i].Sequence < rejections[j].Sequence })
}
