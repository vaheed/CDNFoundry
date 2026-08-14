@php($isRotation = ($flow ?? 'enrollment') === 'rotation')
@php($environment = "EDGE_ID={$edgeId}\nEDGE_BOOTSTRAP_TOKEN={$bootstrapToken}")

<div class="cdn-enrollment">
    <div class="cdn-enrollment-layout">
        <div class="cdn-enrollment-sidebar">
            <div class="cdn-enrollment-host">
                <span class="cdn-enrollment-host-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-server-stack" />
                </span>
                <div>
                    <div class="cdn-enrollment-kicker">Where to use these values</div>
                    <strong>Prepared edge host</strong>
                    <p>
                        Use the host that will run <code>{{ $edgeName }}</code>. Its display name does not
                        need to match a Fleet, Terraform, Ansible, or server hostname.
                    </p>
                </div>
            </div>

            <div class="cdn-enrollment-credentials">
                <div class="cdn-enrollment-credential" x-data="{ copied: false }">
                    <div class="cdn-enrollment-credential-header">
                        <div><div class="cdn-enrollment-kicker">Edge identity</div><strong>UUID</strong></div>
                        <x-filament::button type="button" color="gray" size="sm"
                            x-on:click="navigator.clipboard.writeText($refs.edgeId.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                            <span x-show="! copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                        </x-filament::button>
                    </div>
                    <code x-ref="edgeId" class="cdn-enrollment-value">{{ $edgeId }}</code>
                </div>

                <div class="cdn-enrollment-credential cdn-enrollment-credential--secret" x-data="{ copied: false }">
                    <div class="cdn-enrollment-credential-header">
                        <div><div class="cdn-enrollment-kicker">{{ $isRotation ? 'Replacement secret' : 'Secret' }} · shown once</div><strong>Bootstrap token</strong></div>
                        <x-filament::button type="button" color="warning" size="sm"
                            x-on:click="navigator.clipboard.writeText($refs.bootstrapToken.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                            <span x-show="! copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
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
                        <strong>Copy the environment block</strong>
                        <p>Paste these lines into <code>.env.prod</code> on the prepared edge host and keep the file mode <code>0600</code>.</p>
                    </div>
                </div>
                <div class="cdn-enrollment-command-wrap">
                    <x-filament::button class="cdn-enrollment-copy-command" type="button" color="gray" size="sm"
                        x-on:click="navigator.clipboard.writeText($refs.environment.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                        <span x-show="! copied">Copy environment</span><span x-show="copied" x-cloak>Copied</span>
                    </x-filament::button>
                    <pre x-ref="environment" class="cdn-enrollment-command">{{ $environment }}</pre>
                </div>
            </section>

            <section class="cdn-enrollment-step" x-data="{ copied: false }">
                <div class="cdn-enrollment-step-heading">
                    <span class="cdn-enrollment-step-number">2</span>
                    <div>
                        <strong>{{ $isRotation ? 'Restart' : 'Start' }} the edge profile</strong>
                        <p>Run this command now, or add <code>--profile edge</code> to the Compose <code>up</code> command in <code>start.sh</code> for future starts.</p>
                    </div>
                </div>
                <div class="cdn-enrollment-command-wrap">
                    <x-filament::button class="cdn-enrollment-copy-command" type="button" color="gray" size="sm"
                        x-on:click="navigator.clipboard.writeText($refs.startCommand.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)">
                        <span x-show="! copied">Copy command</span><span x-show="copied" x-cloak>Copied</span>
                    </x-filament::button>
                    <pre x-ref="startCommand" class="cdn-enrollment-command">cd /opt/cdnfoundry
sudo docker compose --env-file .env.prod --profile edge up -d</pre>
                </div>
            </section>
        </div>
    </div>

    <div class="cdn-enrollment-next">
        <strong>Done:</strong>
        wait for a fresh heartbeat on this page. No Fleet command, bundle rerender, or second transfer is required.
    </div>

    <details class="cdn-enrollment-followup">
        <summary>Using another deployment system?</summary>
        <p class="cdn-enrollment-followup-intro">
            Supply the same two environment variables through your secret manager or configuration tool and enable the edge role.
            The control plane does not require CDNFoundry Fleet or a particular deployment tool.
        </p>
    </details>
</div>
