<div class="space-y-5 text-sm">
    <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-warning-900 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-100">
        Run the commands below on the <strong>Fleet authority</strong> where
        <code>fleet.json</code> and <code>/var/lib/cdnfoundry-fleet</code> exist.
        Do not run them inside the edge host or a DNS API container.
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between gap-3">
            <strong>Edge UUID</strong>
            <x-filament::button type="button" color="gray" size="sm"
                x-on:click="navigator.clipboard.writeText($refs.edgeId.textContent.trim())">
                Copy UUID
            </x-filament::button>
        </div>
        <pre x-ref="edgeId" class="overflow-x-auto rounded-lg bg-gray-950 p-3 text-xs text-white">{{ $edgeId }}</pre>
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between gap-3">
            <strong>One-time bootstrap token</strong>
            <x-filament::button type="button" color="gray" size="sm"
                x-on:click="navigator.clipboard.writeText($refs.bootstrapToken.textContent.trim())">
                Copy token
            </x-filament::button>
        </div>
        <pre x-ref="bootstrapToken" class="overflow-x-auto whitespace-pre-wrap break-all rounded-lg bg-gray-950 p-3 text-xs text-white">{{ $bootstrapToken }}</pre>
    </div>

    <div class="space-y-2">
        <strong>1. Create the protected temporary token file</strong>
        <p>This command prompts for the token without putting it in shell history or echoing it. Paste the token above and press Enter.</p>
        <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-3 text-xs text-white">sudo install -m 0600 /dev/null {{ $tokenPath }}
sudo bash -c 'read -rsp "Paste bootstrap token: " token; printf "\n"; printf "%s\n" "$token" &gt; {{ $tokenPath }}'</pre>
    </div>

    <div class="space-y-2">
        <strong>2. Store this registration in Fleet state</strong>
        <p>
            This uses edge name <code>{{ $nodeName }}</code> as the Fleet node name.
            It must exactly match the node's <code>name</code> in <code>fleet.json</code>.
        </p>
        <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-3 text-xs text-white">sudo ./scripts/cdnfoundry-fleet \
  --state-dir /var/lib/cdnfoundry-fleet \
  configure-edge-registration \
  --node {{ $shellNodeName }} \
  --edge-id {{ $edgeId }} \
  --bootstrap-token-file {{ $tokenPath }} \
  --non-interactive</pre>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
        After this command succeeds, rerender the matching node and transfer its
        <strong>complete bundle</strong> to that same PoP host. Do not copy a token
        file into a running container and do not edit <code>.env.prod</code> by hand.
    </div>
</div>
