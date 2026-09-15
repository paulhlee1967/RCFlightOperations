<?php
/**
 * includes/member_wizard_nav.php
 *
 * Step indicator for the new-member wizard (steps 1–3 on member_wizard.php,
 * steps 4–5 on member_process.php when ?wizard=1).
 */

/**
 * @return list<array{key:string, label:string, number:int}>
 */
function member_wizard_steps(): array
{
    return [
        ['key' => 'contact',    'label' => 'Contact',       'number' => 1],
        ['key' => 'compliance', 'label' => 'Compliance',    'number' => 2],
        ['key' => 'membership', 'label' => 'Membership',    'number' => 3],
        ['key' => 'record',     'label' => 'Record signup', 'number' => 4],
        ['key' => 'fulfill',    'label' => 'Print & mail',  'number' => 5],
    ];
}

/**
 * Render the horizontal wizard stepper.
 *
 * @param string $currentStep  contact | compliance | membership | record | fulfill
 */
function render_member_wizard_nav(string $currentStep): void
{
    $steps = member_wizard_steps();
    $currentIndex = 0;
    foreach ($steps as $i => $step) {
        if ($step['key'] === $currentStep) {
            $currentIndex = $i;
            break;
        }
    }
    ?>
    <nav class="member-wizard-nav mb-4" aria-label="New member progress">
        <ol class="member-wizard-steps list-unstyled d-flex flex-wrap gap-2 gap-md-0 mb-0">
            <?php foreach ($steps as $i => $step):
                $done    = $i < $currentIndex;
                $active  = $i === $currentIndex;
                $pending = $i > $currentIndex;
                $stateClass = $done ? 'is-done' : ($active ? 'is-active' : 'is-pending');
            ?>
            <li class="member-wizard-step flex-fill <?= h($stateClass) ?>">
                <span class="member-wizard-step-inner d-flex align-items-center gap-2">
                    <span class="member-wizard-step-badge" aria-hidden="true">
                        <?php if ($done): ?>✓<?php else: ?><?= (int) $step['number'] ?><?php endif; ?>
                    </span>
                    <span class="member-wizard-step-label d-none d-md-inline"><?= h($step['label']) ?></span>
                </span>
            </li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <?php
}

/** Wizard form steps (1–3 on member_wizard.php). */
const MEMBER_WIZARD_FORM_STEPS = ['contact', 'compliance', 'membership'];

/**
 * 0-based index for a wizard form step key.
 */
function member_wizard_step_index(string $step): int
{
    $idx = array_search($step, MEMBER_WIZARD_FORM_STEPS, true);
    return $idx === false ? 0 : (int) $idx;
}

/**
 * Build a member_wizard.php URL, optionally opening a step and returning to signup.
 */
function member_wizard_url(?int $memberId, ?string $step = null, ?string $return = null): string
{
    $params = [];
    if ($memberId) {
        $params['id'] = $memberId;
    }
    if ($step !== null && in_array($step, MEMBER_WIZARD_FORM_STEPS, true)) {
        $params['step'] = $step;
    }
    if ($return === 'process') {
        $params['return'] = 'process';
    }

    return 'member_wizard.php' . ($params === [] ? '' : '?' . http_build_query($params));
}

/**
 * Fix link from member_process review warnings back to the right edit surface.
 *
 * @param array{tab?:string, field?:string} $warning
 */
function member_process_fix_url(bool $fromWizard, int $memberId, array $warning): string
{
    $tab = (string) ($warning['tab'] ?? 'contact');
    if ($fromWizard) {
        $url = member_wizard_url($memberId, $tab, 'process');
        if (!empty($warning['field'])) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'field=' . rawurlencode((string) $warning['field']);
        }
        return $url;
    }

    return 'member_edit.php?id=' . $memberId . '#pane-' . rawurlencode($tab);
}
