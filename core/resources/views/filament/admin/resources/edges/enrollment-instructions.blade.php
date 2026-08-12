@php($isRotation = ($flow ?? 'enrollment') === 'rotation')

<div class="cdn-enrollment">
    <div class="cdn-enrollment-layout">
        <div class="cdn-enrollment-sidebar">
            <div class="cdn-enrollment-host">
                <span class="cdn-enrollment-host-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-command-line" />
                </span>
                <div>
                    <div class="cdn-enrollment-kicker">Command location</div>
                    <strong>Fleet authority</strong>
                    <p>
                        The machine containing <code>fleet.json</code> and
                        <code>/var/lib/cdnfoundry-fleet</code>—not the PoP or DNS API container.
                    </p>
                </div>
            </div>

            <div class="cdn-enrollment-credentials">
                <div class="cdn-enrollment-credential" x-data="{ copied: false }">
                    <div class="cdn-enrollment-credential-header">
                        <div>
                            <div class="cdn-enrollment-kicker">Edge identity</div>
                            <strong>UUID</strong>
                        </div>
                        <x-filament::button type="button" color="gray" size="sm"
                            x-on:click="navigator.clipboard.writeText($refs.edgeId.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                            <span x-show="! copied">Copy</span>
                            <span x-show="copied" x-cloak>Copied</span>
                        </x-filament::button>
                    </div>
                    <code x-ref="edgeId" class="cdn-enrollment-value">{{ $edgeId }}</code>
                </div>

                <div class="cdn-enrollment-credential cdn-enrollment-credential--secret" x-data="{ copied: false }">
                    <div class="cdn-enrollment-credential-header">
                        <div>
                            <div class="cdn-enrollment-kicker">{{ $isRotation ? 'Replacement secret' : 'Secret' }} · shown once</div>
                            <strong>Bootstrap token</strong>
                        </div>
                        <x-filament::button type="button" color="warning" size="sm"
                            x-on:click="navigator.clipboard.writeText($refs.bootstrapToken.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                            <span x-show="! copied">Copy</span>
                            <span x-show="copied" x-cloak>Copied</span>
                        </x-filament::button>
                    </div>
                    <code x-ref="bootstrapToken" class="cdn-enrollment-value cdn-enrollment-value--secret">{{ $bootstrapToken }}</code>
                </div>
            </div>
        </div>

        <div class="cdn-enrollment-steps">
            <section class="cdn-enrollment-step" x-data="{ copied: false }">
                <div class="cdn-enrollment-step-heading">
                    <span class="cdn-enrollment-step-number">1</span>
                    <div>
                        <strong>Create the protected token file</strong>
                        <p>Paste the token at the hidden prompt and press Enter.</p>
                    </div>
                </div>
                <div class="cdn-enrollment-command-wrap">
                    <x-filament::button class="cdn-enrollment-copy-command" type="button" color="gray" size="sm"
                        x-on:click="navigator.clipboard.writeText($refs.tokenFileCommand.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                        <span x-show="! copied">Copy command</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </x-filament::button>
                    <pre x-ref="tokenFileCommand" class="cdn-enrollment-command">sudo install -m 0600 /dev/null {{ $tokenPath }}
sudo bash -c 'read -rsp "Paste bootstrap token: " token; printf "\n"; printf "%s\n" "$token" &gt; {{ $tokenPath }}'</pre>
                </div>
            </section>

            <section class="cdn-enrollment-step" x-data="{ copied: false }">
                <div class="cdn-enrollment-step-heading">
                    <span class="cdn-enrollment-step-number">2</span>
                    <div>
                        <strong>Save registration in Fleet state</strong>
                        <p>Edge <code>{{ $nodeName }}</code> must match its <code>fleet.json</code> node name.</p>
                    </div>
                </div>
                <div class="cdn-enrollment-command-wrap">
                    <x-filament::button class="cdn-enrollment-copy-command" type="button" color="gray" size="sm"
                        x-on:click="navigator.clipboard.writeText($refs.registrationCommand.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                        <span x-show="! copied">Copy command</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </x-filament::button>
                    <pre x-ref="registrationCommand" class="cdn-enrollment-command">sudo ./scripts/cdnfoundry-fleet \
  --state-dir /var/lib/cdnfoundry-fleet \
  configure-edge-registration \
  --node {{ $shellNodeName }} \
  --edge-id {{ $edgeId }} \
  --bootstrap-token-file {{ $tokenPath }} \
  --non-interactive</pre>
                </div>
            </section>
        </div>
    </div>

    <div class="cdn-enrollment-next">
        <strong>Next:</strong>
        after command 2 reports <code>"status": "configured"</code>, rerender
        <code>{{ $nodeName }}</code> and transfer its complete bundle to the matching PoP{{ $isRotation ? ', then recreate only edge-agent' : '' }}.
        Never edit <code>.env.prod</code> manually.
    </div>

    <details class="cdn-enrollment-followup">
        <summary>Show deployment and token-cleanup commands</summary>
        <p class="cdn-enrollment-followup-intro">
            These commands begin only after command 2 succeeds. Transfer the
            <strong>complete matching bundle</strong> whenever the host context changes.
        </p>

        <div class="cdn-enrollment-followup-grid">
            <section class="cdn-enrollment-step" x-data="{ copied: false }">
                <div class="cdn-enrollment-step-heading">
                    <span class="cdn-enrollment-step-number">3</span>
                    <div><strong>Fleet authority: render {{ $nodeName }}</strong></div>
                </div>
                <div class="cdn-enrollment-command-wrap">
                    <x-filament::button class="cdn-enrollment-copy-command" type="button" color="gray" size="sm"
                        x-on:click="navigator.clipboard.writeText($refs.renderCommand.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                        <span x-show="! copied">Copy command</span><span x-show="copied" x-cloak>Copied</span>
                    </x-filament::button>
                    <pre x-ref="renderCommand" class="cdn-enrollment-command">sudo ./scripts/cdnfoundry-fleet \
  --state-dir /var/lib/cdnfoundry-fleet \
  --output-dir /var/lib/cdnfoundry-fleet/bundles \
  render --node {{ $shellNodeName }}</pre>
                </div>
            </section>

            <section class="cdn-enrollment-step" x-data="{ copied: false }">
                <div class="cdn-enrollment-step-heading">
                    <span class="cdn-enrollment-step-number">4</span>
                    <div>
                        <strong>PoP {{ $nodeName }}: activate bundle</strong>
                        <p>Run after transferring the complete rendered bundle.</p>
                    </div>
                </div>
                <div class="cdn-enrollment-command-wrap">
                    <x-filament::button class="cdn-enrollment-copy-command" type="button" color="gray" size="sm"
                        x-on:click="navigator.clipboard.writeText($refs.activateCommand.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                        <span x-show="! copied">Copy commands</span><span x-show="copied" x-cloak>Copied</span>
                    </x-filament::button>
                    <pre x-ref="activateCommand" class="cdn-enrollment-command">cd /opt/cdnfoundry
sha256sum -c SHA256SUMS
./validate.sh
@if ($isRotation)
docker compose --env-file .env.prod up -d --force-recreate edge-agent
@else
sudo ./start.sh
@endif
</pre>
                </div>
            </section>
        </div>

        <div class="cdn-enrollment-cleanup" x-data="{ copied: false }">
            <div class="cdn-enrollment-cleanup-heading">
                <div>
                    <strong>After enrollment and fresh heartbeat</strong>
                    <p>Clear the consumed token, rerender, remove its temporary file, transfer the complete token-free bundle, and recreate only <code>edge-agent</code>.</p>
                </div>
                <x-filament::button type="button" color="gray" size="sm"
                    x-on:click="navigator.clipboard.writeText($refs.cleanupCommand.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                    <span x-show="! copied">Copy Fleet commands</span><span x-show="copied" x-cloak>Copied</span>
                </x-filament::button>
            </div>
            <pre x-ref="cleanupCommand" class="cdn-enrollment-command">sudo ./scripts/cdnfoundry-fleet --state-dir /var/lib/cdnfoundry-fleet clear-edge-bootstrap-token --node {{ $shellNodeName }} --non-interactive
sudo ./scripts/cdnfoundry-fleet --state-dir /var/lib/cdnfoundry-fleet --output-dir /var/lib/cdnfoundry-fleet/bundles render --node {{ $shellNodeName }}
sudo rm -f {{ $tokenPath }}</pre>
        </div>
    </details>
</div>
