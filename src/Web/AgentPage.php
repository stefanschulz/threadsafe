<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use InvalidArgumentException;
use RuntimeException;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\SiteProfile;

/**
 * The page "The agent" of the wizard of the backup tool (docs/PHASE13.md 9.3, M1): where a person on the host pairs the tool with a central ThreadSafe,
 * switches the agent on and off and sets who may ask, how many backups the host keeps and whether plain http is answered. A host without a shell (the case
 * of the plan) has nothing else; the plugin will show the same things. The work is {@see AgentSetup}'s; this class turns what was posted into calls
 * and the state into HTML.
 */
final class AgentPage
{
    private readonly AgentSetup $setup;

    public function __construct(private readonly BackupWorkspace $workspace)
    {
        $this->setup = new AgentSetup($workspace);
    }

    /**
     * Does what a posted form asked.
     *
     * @param string $action `agent-pair`, `agent-save`, `agent-on`, `agent-off`, `agent-sync` or `agent-unpair`
     * @param string $host   the host name the page was asked for (for the address in the pairing string), or ''
     *
     * @return array{notice: string, error: string, pairing: string} what to say on top (a done thing, a refusal) and, once, the pairing string
     */
    public function handle(string $action, Request $request, string $host): array
    {
        $result = ['notice' => '', 'error' => '', 'pairing' => ''];
        $config = $this->setup->config();
        try {
            switch ($action) {
                case 'agent-pair':
                    $url = trim((string) $request->post('agent_url'));
                    $url = $url !== '' ? $url : (AgentSetup::addressOf($request, $host) ?? '');
                    $this->applySettings($config, $request);
                    $result['pairing'] = $this->setup->pair($this->site(), trim((string) $request->post('agent_id')), $url);
                    $result['notice'] = 'Paired. The agent is switched off until you switch it on, below; give the string to the central ThreadSafe first.';
                    break;
                case 'agent-save':
                    $this->applySettings($config, $request);
                    $result['notice'] = 'Saved.';
                    break;
                case 'agent-on':
                    if (!$config->isPaired()) {
                        throw new InvalidArgumentException('Pair the agent first.');
                    }
                    $config->enable(true);
                    $result['notice'] = 'The agent is on. It answers signed requests of the central ThreadSafe and nothing else.';
                    break;
                case 'agent-off':
                    $config->enable(false);
                    $result['notice'] = 'The agent is off. It answers nobody.';
                    break;
                case 'agent-sync':
                    $this->setup->sync($this->site());
                    $result['notice'] = 'The settings of this tool (the site, what is left out, the keys, the size of a part) are the ones of the agent now.';
                    break;
                case 'agent-unpair':
                    $this->setup->unpair();
                    $result['notice'] = 'The pairing is forgotten and the agent is off. The backups that lie on this host stay.';
                    break;
            }
        } catch (InvalidArgumentException | ProfileException | RuntimeException $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @throws InvalidArgumentException a value is not valid
     */
    private function applySettings(AgentConfig $config, Request $request): void
    {
        $keep = trim((string) $request->post('agent_keep'));
        if ($keep !== '' && preg_match('/^\d{1,2}$/D', $keep) !== 1) {
            throw new InvalidArgumentException('The number of backups that the host keeps is a whole number from 0 to 20.');
        }
        $config->setAllow(preg_split('/\r\n|\r|\n/', (string) $request->post('agent_allow')) ?: []);
        $config->setKeepOnHost($keep === '' ? $config->keepOnHost() : (int) $keep);
        $config->setPlainAllowed($request->post('agent_plain') === '1');
    }

    /**
     * @throws ProfileException the tool has no profile yet (the site and the options come first)
     */
    private function site(): SiteProfile
    {
        $profiles = $this->workspace->profiles();
        if (!$profiles->exists(BackupWorkspace::PROFILE)) {
            throw new ProfileException('Set up the site and the options first (the first pages of this tool): the agent makes the backups that you set up there.');
        }

        return $profiles->load(BackupWorkspace::PROFILE);
    }

    /**
     * @param array{notice: string, error: string, pairing: string} $result
     * @param string                                                $address the address of the tool as the request saw it, to start the form with
     */
    public function body(string $csrf, string $address, array $result): string
    {
        $e = Html::e(...);
        $config = $this->setup->config();
        $problem = null;
        try {
            $problem = AgentSetup::problem($this->site());
        } catch (ProfileException $x) {
            $problem = $x->getMessage();
        }

        $html = '<p>The agent lets <strong>a central ThreadSafe</strong> start, watch and fetch backups of this site by itself, so that nobody has to click through this tool each time. '
            . 'It answers only requests that are signed with the secret of the pairing, and nobody else: to a stranger it looks like a file that is not there. '
            . 'It makes <strong>encrypted</strong> backups of the site as you set it up on the earlier pages; this host never holds the secret key.</p>';
        if ($result['error'] !== '') {
            $html .= '<p class="fail">' . $e($result['error']) . '</p>';
        }
        if ($result['notice'] !== '') {
            $html .= '<p class="ok">' . $e($result['notice']) . '</p>';
        }
        if ($result['pairing'] !== '') {
            $html .= '<div class="box"><p><strong>The pairing string.</strong> Copy it now: it is shown <strong>once</strong>. It holds the secret of the pairing, so treat it like a password. '
                . 'Paste it into the central ThreadSafe (<code>profile add ... --pairing-file=FILE</code>, or the field of the form for a remote profile).</p>'
                . '<pre><code>' . $e($result['pairing']) . '</code></pre>'
                . '<p class="help">Fingerprint of the secret, to compare on both sides: <code>' . $e(AgentPairing::readable(AgentPairing::fingerprint($config->secret()))) . '</code></p></div>';
        }

        if ($problem !== null) {
            return $html . '<p class="fail">' . $e($problem) . '</p><p><a class="button secondary" href="?step=options">Back to the options</a></p>';
        }

        $allow = implode("\n", $config->allow());
        $settings = '<label for="agent_allow">Addresses that may ask (one per line; empty: every address)</label><textarea id="agent_allow" name="agent_allow" rows="3">' . $e($allow) . '</textarea>'
            . '<span class="help">The address of the central server, like <code>203.0.113.7</code> or a range like <code>198.51.100.0/24</code>. A host behind a proxy sees the proxy\'s address.</span>'
            . '<label for="agent_keep">Backups that this host keeps after one is safe elsewhere</label><input id="agent_keep" type="text" name="agent_keep" value="' . $e((string) $config->keepOnHost()) . '" inputmode="numeric">'
            . '<span class="help">1 keeps the newest, so that a transfer that failed in the night can be fetched again. 0 deletes a backup as soon as the central ThreadSafe holds it.</span>'
            . '<label><input type="checkbox" name="agent_plain" value="1"' . ($config->plainAllowed() ? ' checked' : '') . '>Answer plain http too</label>'
            . '<span class="help">Not recommended: use https. The requests are signed and the archive is encrypted either way, but everything else travels in the clear.</span>';

        if (!$config->isPaired()) {
            return $html . '<h3>Pair this tool</h3>' . $this->form(
                '<label for="agent_id">Id of the profile on the central ThreadSafe</label><input id="agent_id" type="text" name="agent_id" value="' . $e(BackupWorkspace::PROFILE) . '">'
                . '<span class="help">The backups are made under this id, so that the central ThreadSafe finds them: lower-case letters, digits, - and _.</span>'
                . '<label for="agent_url">Address of this tool, as the central ThreadSafe reaches it</label><input id="agent_url" type="text" name="agent_url" value="' . $e($address) . '">'
                . $settings . '<p><button name="action" value="agent-pair">Pair</button> <a class="button secondary" href="?step=summary">Back</a></p>',
                $csrf,
            );
        }

        $on = $config->isOn();
        $html .= '<div class="box"><p>Paired with the profile <code>' . $e($config->profile()) . '</code> (fingerprint <code>' . $e(AgentPairing::readable(AgentPairing::fingerprint($config->secret())))
            . '</code>, ' . $e($config->pairedAt() > 0 ? gmdate('Y-m-d H:i', $config->pairedAt()) . ' UTC' : 'at some time') . '). The agent is <strong>' . ($on ? 'on' : 'off') . '</strong>.</p></div>'
            . $this->form('<p>' . ($on
                ? '<button class="secondary" name="action" value="agent-off">Switch it off</button>'
                : '<button name="action" value="agent-on">Switch it on</button>') . '</p>', $csrf)
            . '<h3>Settings</h3>' . $this->form($settings . '<p><button name="action" value="agent-save">Save</button></p>', $csrf)
            . '<h3>Changed the site, the keys or what is left out?</h3>' . $this->form('<p><button class="secondary" name="action" value="agent-sync">Use the settings of this tool for the agent again</button></p>', $csrf)
            . '<h3>Pair again, or stop</h3><p class="help">A new pairing makes a new secret: the central ThreadSafe must get the new string. Forgetting the pairing removes the profile of the agent and the secret; backups that lie on this host stay.</p>'
            . $this->form(
                '<label for="agent_id">Id of the profile on the central ThreadSafe</label><input id="agent_id" type="text" name="agent_id" value="' . $e($config->profile()) . '">'
                . '<label for="agent_url">Address of this tool</label><input id="agent_url" type="text" name="agent_url" value="' . $e($address) . '">'
                . '<input type="hidden" name="agent_allow" value="' . $e($allow) . '"><input type="hidden" name="agent_keep" value="' . $e((string) $config->keepOnHost()) . '">'
                . ($config->plainAllowed() ? '<input type="hidden" name="agent_plain" value="1">' : '')
                . '<p><button class="secondary" name="action" value="agent-pair">Pair again</button> <button class="secondary" name="action" value="agent-unpair">Forget the pairing</button></p>',
                $csrf,
            )
            . '<p><a class="button secondary" href="?step=summary">Back</a></p>';

        return $html;
    }

    private function form(string $inner, string $csrf): string
    {
        return '<form method="post"><input type="hidden" name="csrf" value="' . Html::e($csrf) . '">' . $inner . '</form>';
    }
}
