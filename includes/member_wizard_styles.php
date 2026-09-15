<?php
/** Inline styles for member wizard stepper (member_wizard.php, member_process.php?wizard=1). */
?>
<style<?= csp_nonce_attr() ?>>
.member-wizard-steps {
    counter-reset: wizard-step;
}
.member-wizard-step {
    position: relative;
    min-width: 0;
}
.member-wizard-step:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 1.1rem;
    left: calc(50% + 1.25rem);
    right: calc(-50% + 1.25rem);
    height: 2px;
    background: var(--bs-border-color);
    z-index: 0;
}
.member-wizard-step.is-done:not(:last-child)::after {
    background: var(--club-primary, #6f7c3d);
}
.member-wizard-step-inner {
    position: relative;
    z-index: 1;
    flex-direction: column;
    text-align: center;
    padding: 0 .25rem;
}
@media (min-width: 768px) {
    .member-wizard-step-inner {
        flex-direction: row;
        text-align: left;
    }
}
.member-wizard-step-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    font-size: .875rem;
    font-weight: 600;
    border: 2px solid var(--bs-border-color);
    background: #fff;
    color: var(--bs-secondary-color);
    flex-shrink: 0;
}
.member-wizard-step.is-active .member-wizard-step-badge {
    border-color: var(--club-primary, #6f7c3d);
    background: var(--club-primary, #6f7c3d);
    color: #fff;
}
.member-wizard-step.is-done .member-wizard-step-badge {
    border-color: var(--club-primary, #6f7c3d);
    background: var(--club-primary, #6f7c3d);
    color: #fff;
}
.member-wizard-step-label {
    font-size: .8125rem;
    font-weight: 500;
    color: var(--bs-secondary-color);
    line-height: 1.2;
}
.member-wizard-step.is-active .member-wizard-step-label {
    color: var(--bs-body-color);
    font-weight: 600;
}
.wizard-step-panel { display: none; }
.wizard-step-panel.is-active { display: block; }
</style>
