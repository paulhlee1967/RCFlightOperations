<?php
/**
 * Include after a password input to show a live strength indicator.
 * The input must have class "password-strength-input".
 * Place a sibling <div class="password-strength small mt-1" aria-live="polite"></div> immediately after the input.
 * Optionally give the input data-strength-target="id" to point to a specific element by id.
 *
 * The strengthLabel() logic intentionally mirrors password_strength_label() in
 * password_policy.php: server-side code enforces policy; this script only
 * provides instant UX feedback. The two must be updated together if rules change.
 */
?>
<script<?= csp_nonce_attr() ?>>
document.addEventListener('DOMContentLoaded', function () {
    function strengthLabel(p) {
        if (p.length === 0) return { label: '', class: '' };
        var hasDigit = /[0-9]/.test(p);
        var hasSymbol = /[^a-zA-Z0-9]/.test(p);
        var hasUpper = /[A-Z]/.test(p);
        var hasLower = /[a-z]/.test(p);
        var variety = (hasDigit ? 1 : 0) + (hasSymbol ? 1 : 0) + (hasUpper ? 1 : 0) + (hasLower ? 1 : 0);
        if (p.length >= 12 && variety >= 3) return { label: 'Strong', class: 'text-success' };
        if (p.length >= 10 && variety >= 2) return { label: 'Good', class: 'text-primary' };
        if (p.length >= 8 && (hasDigit || hasSymbol)) return { label: 'Fair', class: 'text-info' };
        return { label: 'Weak', class: 'text-muted' };
    }

    document.querySelectorAll('.password-strength-input').forEach(function (inp) {
        var target = inp.nextElementSibling && inp.nextElementSibling.classList.contains('password-strength')
            ? inp.nextElementSibling
            : (inp.getAttribute('data-strength-target') ? document.getElementById(inp.getAttribute('data-strength-target')) : null);
        if (!target) return;

        function update() {
            var s = strengthLabel(inp.value);
            target.textContent = s.label ? 'Strength: ' + s.label : '';
            target.className = 'password-strength small mt-1' + (s.class ? ' ' + s.class : '');
        }
        inp.addEventListener('input', update);
        inp.addEventListener('blur', update);
    });
});
</script>
