/**
 * Public membership application form — fee quotes, signature pad, Stripe Payment Element.
 */
(function () {
    'use strict';

    const cfg = window.MEMBERSHIP_APPLY || {};
    const form = document.getElementById('membership-apply-form');
    if (!form) return;

    const feeSummary = document.getElementById('fee-summary');
    const paymentCard = document.getElementById('payment-card');
    const paymentPlaceholder = document.getElementById('payment-placeholder');
    const paymentElementMount = document.getElementById('payment-element');
    const paymentErrors = document.getElementById('payment-errors');
    const formErrors = document.getElementById('form-errors');
    const submitBtn = document.getElementById('submit-btn');
    const badgePhoto = document.getElementById('badge_photo');
    const badgeWrap = document.getElementById('badge-photo-wrap');
    const badgeStar = document.getElementById('badge-required-star');
    const signatureCanvas = document.getElementById('signature-pad');
    const signatureData = document.getElementById('signature_data');
    const signatureClear = document.getElementById('signature-clear');
    const applyStep2 = document.getElementById('apply-step-2');
    const amaVerifyBtn = document.getElementById('ama-verify-btn');
    const amaVerifyErrors = document.getElementById('ama-verify-errors');
    const amaGateForm = document.getElementById('ama-gate-form');
    const amaGateSuccess = document.getElementById('ama-gate-success');

    let amaVerified = !!cfg.amaVerified;
    let stripe = null;
    let elements = null;
    let paymentElement = null;
    let quoteWaivesPayment = false;
    let paymentMounted = false;
    let pendingClientSecret = null;
    let pendingConfirmUrl = null;

    function money(n) {
        return '$' + Number(n).toFixed(2);
    }

    function getKind() {
        const checked = form.querySelector('input[name="application_kind"]:checked');
        if (checked) return checked.value;
        const hidden = form.querySelector('input[name="application_kind"][type="hidden"]');
        return hidden ? hidden.value : 'new';
    }

    function updateBadgeRequirement() {
        const isNew = getKind() === 'new';
        if (badgePhoto) {
            badgePhoto.required = isNew;
        }
        if (badgeWrap) {
            badgeWrap.classList.toggle('opacity-50', !isNew);
        }
        if (badgeStar) {
            badgeStar.classList.toggle('d-none', !isNew);
        }
    }

    function formQuoteData() {
        const data = new FormData();
        data.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
        data.append('application_kind', getKind());
        data.append('membership_type_slot', form.membership_type_slot.value);
        data.append('coupon_code', form.coupon_code.value);
        if (form.email && form.email.value) {
            data.append('email', form.email.value);
        }
        if (form.ama_number && form.ama_number.value) {
            data.append('ama_number', form.ama_number.value);
        }
        return data;
    }

    async function refreshQuote() {
        const slot = form.membership_type_slot.value;
        if (!slot) {
            feeSummary.classList.add('d-none');
            return;
        }
        try {
            const res = await fetch(cfg.quoteUrl, { method: 'POST', body: formQuoteData() });
            const json = await res.json();
            if (!json.ok || !json.quote) {
                if (json.errors && json.errors.application_kind) {
                    showErrors(json.errors, json.error);
                }
                return;
            }
            const q = json.quote;
            document.getElementById('fee-dues').textContent = money(q.dues);
            document.getElementById('fee-initiation').textContent = money(q.initiation);
            document.getElementById('fee-processing').textContent = money(q.processing_fee);
            document.getElementById('fee-total').textContent = money(q.total);
            const compEl = document.getElementById('fee-complimentary');
            if (compEl) {
                if (q.complimentary_message) {
                    compEl.textContent = q.complimentary_message;
                    compEl.classList.remove('d-none');
                } else {
                    compEl.textContent = '';
                    compEl.classList.add('d-none');
                }
            }
            feeSummary.classList.remove('d-none');
            quoteWaivesPayment = !!q.waive_payment;
            if (paymentCard) {
                paymentCard.classList.toggle('d-none', quoteWaivesPayment);
            }
        } catch (e) {
            console.error(e);
        }
    }

    async function mountPaymentElement(clientSecret) {
        if (!clientSecret) {
            return false;
        }
        if (!cfg.stripePublishableKey) {
            showErrors(null, 'Payment is not configured — add the Stripe publishable key (pk_test_…) under Installation, not just the secret key.');
            return false;
        }
        if (typeof Stripe === 'undefined') {
            showErrors(null, 'Stripe could not load. Check your connection or ad blocker.');
            return false;
        }
        if (!stripe) {
            stripe = Stripe(cfg.stripePublishableKey);
        }
        if (!paymentMounted) {
            elements = stripe.elements({ clientSecret, appearance: { theme: 'stripe' } });
            paymentElement = elements.create('payment');
            paymentElement.mount(paymentElementMount);
            paymentMounted = true;
        }
        if (paymentPlaceholder) {
            paymentPlaceholder.classList.add('d-none');
        }
        paymentElementMount.classList.remove('d-none');
        return true;
    }

    function showErrors(errors, message) {
        form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        const lines = [];
        if (message) lines.push(message);
        if (errors && typeof errors === 'object') {
            Object.entries(errors).forEach(([field, msg]) => {
                lines.push(msg);
                const input = form.querySelector('[name="' + field + '"]');
                if (input) {
                    input.classList.add('is-invalid');
                }
            });
        }
        if (lines.length === 0) {
            formErrors.classList.add('d-none');
            formErrors.textContent = '';
            return;
        }
        formErrors.innerHTML = lines.map((l) => '<div>' + escapeHtml(l) + '</div>').join('');
        formErrors.classList.remove('d-none');
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function formatUsDateInput(input) {
        const digits = input.value.replace(/\D/g, '').slice(0, 8);
        let formatted = '';
        if (digits.length > 0) {
            formatted = digits.slice(0, 2);
        }
        if (digits.length > 2) {
            formatted += '/' + digits.slice(2, 4);
        }
        if (digits.length > 4) {
            formatted += '/' + digits.slice(4, 8);
        }
        input.value = formatted;
    }

    function formatUsPhoneInput(input) {
        const digits = input.value.replace(/\D/g, '').slice(0, 10);
        let formatted = '';
        if (digits.length > 0) {
            formatted = '(' + digits.slice(0, 3);
        }
        if (digits.length >= 3) {
            formatted += ') ' + digits.slice(3, 6);
        }
        if (digits.length > 6) {
            formatted += '-' + digits.slice(6, 10);
        }
        input.value = formatted;
    }

    function bindMaskedInput(input, formatter) {
        input.addEventListener('input', () => formatter(input));
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text') || '';
            input.value = text;
            formatter(input);
        });
    }

    document.querySelectorAll('.js-date-us').forEach((input) => {
        bindMaskedInput(input, formatUsDateInput);
    });

    document.querySelectorAll('.js-phone-us').forEach((input) => {
        bindMaskedInput(input, formatUsPhoneInput);
    });

    function showAmaVerifyError(message) {
        if (!amaVerifyErrors) return;
        if (!message) {
            amaVerifyErrors.classList.add('d-none');
            amaVerifyErrors.textContent = '';
            return;
        }
        amaVerifyErrors.textContent = message;
        amaVerifyErrors.classList.remove('d-none');
    }

    function configureApplicationKind(eligible, message) {
        if (!cfg.renewalOpen) return;

        const renewalRadio = document.getElementById('kind_renewal');
        const newRadio = document.getElementById('kind_new');
        const notice = document.getElementById('renewal-eligibility-notice');
        if (!renewalRadio || !newRadio) return;

        if (eligible) {
            renewalRadio.disabled = false;
            renewalRadio.checked = true;
            newRadio.checked = false;
            if (notice) {
                notice.classList.add('d-none');
                notice.textContent = '';
            }
        } else {
            renewalRadio.disabled = true;
            renewalRadio.checked = false;
            newRadio.checked = true;
            if (notice) {
                notice.textContent = message || 'Select New member to apply.';
                notice.classList.remove('d-none');
            }
        }
        updateBadgeRequirement();
        refreshQuote();
    }

    function revealApplicationStep(data) {
        amaVerified = true;
        if (form.first_name) form.first_name.value = data.first_name || '';
        if (form.last_name) form.last_name.value = data.last_name || '';
        if (form.ama_number) form.ama_number.value = data.ama_number || '';
        if (form.ama_expiration) form.ama_expiration.value = data.ama_expiration || '';

        const nameEl = document.getElementById('ama-gate-success-name');
        const numEl = document.getElementById('ama-gate-success-number');
        const expEl = document.getElementById('ama-gate-success-exp');
        if (nameEl) nameEl.textContent = ((data.first_name || '') + ' ' + (data.last_name || '')).trim();
        if (numEl) numEl.textContent = data.ama_number || '';
        if (expEl) expEl.textContent = data.ama_expiration || '';

        if (amaGateForm) amaGateForm.classList.add('d-none');
        if (amaGateSuccess) amaGateSuccess.classList.remove('d-none');
        if (applyStep2) {
            applyStep2.classList.remove('d-none');
            applyStep2.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        configureApplicationKind(!!data.renewal_eligible, data.renewal_message || '');
        if (typeof resizeSignatureCanvas === 'function') {
            requestAnimationFrame(() => resizeSignatureCanvas());
        }
    }

    if (amaVerifyBtn) {
        amaVerifyBtn.addEventListener('click', async () => {
            showAmaVerifyError('');
            const amaNumber = document.getElementById('ama_verify_number')?.value?.trim() || '';
            const lastName = document.getElementById('ama_verify_last_name')?.value?.trim() || '';
            if (!amaNumber || !lastName) {
                showAmaVerifyError('AMA number and last name are required.');
                return;
            }

            amaVerifyBtn.disabled = true;
            amaVerifyBtn.textContent = 'Verifying…';
            const body = new FormData();
            body.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
            body.append('ama_number', amaNumber);
            body.append('last_name', lastName);

            try {
                const res = await fetch(cfg.amaVerifyUrl, { method: 'POST', body });
                const json = await res.json();
                if (!json.ok) {
                    showAmaVerifyError(json.error || 'AMA membership could not be verified.');
                    amaVerifyBtn.disabled = false;
                    amaVerifyBtn.textContent = 'Verify & continue to Step 2';
                    return;
                }
                revealApplicationStep(json.data || {});
            } catch (err) {
                showAmaVerifyError('Network error. Try again.');
                amaVerifyBtn.disabled = false;
                amaVerifyBtn.textContent = 'Verify & continue to Step 2';
            }
        });
    }

    // Signature pad — defer sizing until the canvas is visible (step 2 may start hidden).
    let resizeSignatureCanvas = null;
    if (signatureCanvas && signatureCanvas.getContext) {
        const ctx = signatureCanvas.getContext('2d');
        let drawing = false;

        resizeSignatureCanvas = function () {
            const ratio = window.devicePixelRatio || 1;
            const rect = signatureCanvas.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) {
                return;
            }
            signatureCanvas.width = Math.floor(rect.width * ratio);
            signatureCanvas.height = Math.floor(rect.height * ratio);
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.scale(ratio, ratio);
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.strokeStyle = '#000';
        };

        function pos(e) {
            const rect = signatureCanvas.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            return { x: clientX - rect.left, y: clientY - rect.top };
        }

        function start(e) {
            drawing = true;
            const p = pos(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            e.preventDefault();
        }

        function move(e) {
            if (!drawing) return;
            const p = pos(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            e.preventDefault();
        }

        function end() {
            if (!drawing) return;
            drawing = false;
            if (signatureData) {
                signatureData.value = signatureCanvas.toDataURL('image/png');
            }
        }

        window.addEventListener('resize', resizeSignatureCanvas);
        if (applyStep2 && typeof ResizeObserver !== 'undefined') {
            const observer = new ResizeObserver(() => resizeSignatureCanvas());
            observer.observe(applyStep2);
        }
        requestAnimationFrame(() => resizeSignatureCanvas());

        signatureCanvas.addEventListener('mousedown', start);
        signatureCanvas.addEventListener('mousemove', move);
        signatureCanvas.addEventListener('mouseup', end);
        signatureCanvas.addEventListener('mouseleave', end);
        signatureCanvas.addEventListener('touchstart', start, { passive: false });
        signatureCanvas.addEventListener('touchmove', move, { passive: false });
        signatureCanvas.addEventListener('touchend', end);

        if (signatureClear) {
            signatureClear.addEventListener('click', () => {
                const rect = signatureCanvas.getBoundingClientRect();
                ctx.clearRect(0, 0, rect.width, rect.height);
                if (signatureData) {
                    signatureData.value = '';
                }
            });
        }
    }

    form.querySelectorAll('[name="application_kind"]').forEach((el) => {
        el.addEventListener('change', () => {
            updateBadgeRequirement();
            refreshQuote();
        });
    });
    form.membership_type_slot.addEventListener('change', refreshQuote);
    form.coupon_code.addEventListener('blur', refreshQuote);
    if (form.email) {
        form.email.addEventListener('blur', refreshQuote);
    }
    updateBadgeRequirement();

    if (cfg.amaVerified && cfg.renewalOpen) {
        configureApplicationKind(!!cfg.renewalEligible, cfg.renewalMessage || '');
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        showErrors(null, null);
        paymentErrors.textContent = '';

        if (!amaVerified) {
            showAmaVerifyError('Verify your AMA membership before continuing.');
            document.getElementById('ama-gate-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        if (!signatureData.value) {
            showErrors(null, 'Please sign the application.');
            return;
        }

        if (pendingClientSecret && stripe && elements) {
            submitBtn.disabled = true;
            const { error } = await stripe.confirmPayment({
                elements,
                confirmParams: {
                    return_url: pendingConfirmUrl,
                },
                redirect: 'if_required',
            });
            if (error) {
                paymentErrors.textContent = error.message || 'Payment failed.';
                submitBtn.disabled = false;
                return;
            }
            window.location.href = pendingConfirmUrl;
            return;
        }

        submitBtn.disabled = true;

        const body = new FormData(form);
        body.set('application_kind', getKind());

        let submitJson;
        try {
            const res = await fetch(cfg.submitUrl, { method: 'POST', body });
            submitJson = await res.json();
        } catch (err) {
            showErrors(null, 'Network error. Try again.');
            submitBtn.disabled = false;
            return;
        }

        if (!submitJson.ok) {
            showErrors(submitJson.errors, submitJson.error);
            submitBtn.disabled = false;
            return;
        }

        const confirmUrl = new URL('apply_confirm.php', window.location.href);
        if (window.location.pathname.replace(/\/$/, '').endsWith('/apply')) {
            confirmUrl.pathname = window.location.pathname.replace(/\/apply\/?$/, '/apply/confirm');
        }
        confirmUrl.searchParams.set('id', String(submitJson.application_id));
        confirmUrl.searchParams.set('token', submitJson.confirmation_token);
        pendingConfirmUrl = confirmUrl.href;

        if (submitJson.waive_payment || !submitJson.client_secret) {
            window.location.href = confirmUrl.href;
            return;
        }

        pendingClientSecret = submitJson.client_secret;
        const mounted = await mountPaymentElement(submitJson.client_secret);
        if (!mounted) {
            submitBtn.disabled = false;
            return;
        }
        submitBtn.textContent = 'Complete payment';
        submitBtn.disabled = false;
        paymentCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
})();
