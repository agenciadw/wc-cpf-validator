jQuery(document).ready(function($) {
    'use strict';

    // Real-time validation flag
    var isValidating = false;
    var validationTimeout = null;
    var activeAjax = null;
    var lastValidatedCpf = null; // only digits
    var lastValidationSuccess = null; // boolean
    var lastValidatedCpfMasked = null; // formatted

    var STORAGE_KEY_CPF = 'wcCpfValidator.lastCpfMasked';
    var STORAGE_KEY_CPF_DIGITS = 'wcCpfValidator.lastCpfDigits';
    var STORAGE_KEY_LOCKED = 'wcCpfValidator.locked';
    var STORAGE_KEY_FIRST = 'wcCpfValidator.firstName';
    var STORAGE_KEY_LAST = 'wcCpfValidator.lastName';

    function loadPersistedState() {
        try {
            var d = window.sessionStorage.getItem(STORAGE_KEY_CPF_DIGITS);
            var m = window.sessionStorage.getItem(STORAGE_KEY_CPF);
            var l = window.sessionStorage.getItem(STORAGE_KEY_LOCKED);
            var fn = window.sessionStorage.getItem(STORAGE_KEY_FIRST);
            var ln = window.sessionStorage.getItem(STORAGE_KEY_LAST);
            if (d) lastValidatedCpf = d;
            if (m) lastValidatedCpfMasked = m;
            if (l === '1') lastValidationSuccess = true;
            if (fn) window.wcCpfValidatorFirstName = fn;
            if (ln) window.wcCpfValidatorLastName = ln;
        } catch (e) {}
    }

    function persistState() {
        try {
            if (lastValidatedCpf) window.sessionStorage.setItem(STORAGE_KEY_CPF_DIGITS, lastValidatedCpf);
            if (lastValidatedCpfMasked) window.sessionStorage.setItem(STORAGE_KEY_CPF, lastValidatedCpfMasked);
            window.sessionStorage.setItem(STORAGE_KEY_LOCKED, lastValidationSuccess === true ? '1' : '0');
            if (window.wcCpfValidatorFirstName !== undefined) {
                window.sessionStorage.setItem(STORAGE_KEY_FIRST, (window.wcCpfValidatorFirstName || '').toString());
            }
            if (window.wcCpfValidatorLastName !== undefined) {
                window.sessionStorage.setItem(STORAGE_KEY_LAST, (window.wcCpfValidatorLastName || '').toString());
            }
        } catch (e) {}
    }

    loadPersistedState();

    var handlersBound = false;

    function getNameInputs() {
        return {
            $first: $('input#billing_first_name, input[name="billing_first_name"]').first(),
            $last: $('input#billing_last_name, input[name="billing_last_name"]').first()
        };
    }

    function fillNameFields(firstName, lastName) {
        firstName = (firstName || '').toString().trim().replace(/\s+/g, ' ');
        lastName = (lastName || '').toString().trim().replace(/\s+/g, ' ');

        var inputs = getNameInputs();
        if (inputs.$first.length) {
            inputs.$first.val(firstName).trigger('input').trigger('change').trigger('blur');
        }
        if (inputs.$last.length) {
            inputs.$last.val(lastName).trigger('input').trigger('change').trigger('blur');
        }
    }

    function restoreNameFieldsIfAvailable() {
        var fn = (window.wcCpfValidatorFirstName || '').toString();
        var ln = (window.wcCpfValidatorLastName || '').toString();
        if (!fn && !ln) return;
        // Always overwrite (requested behavior), helps after FunnelKit refresh
        fillNameFields(fn, ln);
    }

    function extractFullName(apiData) {
        if (!apiData || typeof apiData !== 'object') return '';
        var candidates = [
            apiData.nome,
            apiData.nomeCompleto,
            apiData.name,
            apiData.nome_social,
            apiData.nomeSocial,
            apiData.nome_da_pessoa,
            (apiData.pessoa && apiData.pessoa.nome),
            (apiData.dados && apiData.dados.nome),
            (apiData.result && apiData.result.nome),
            // CPFHub returns { success: true, data: { name: "..." } }
            (apiData.data && apiData.data.name),
            (apiData.data && apiData.data.nameUpper),
            (apiData.data && apiData.data.nome),
            (apiData.data && apiData.data.nomeCompleto)
        ];
        for (var i = 0; i < candidates.length; i++) {
            var v = (candidates[i] || '').toString().trim();
            if (v) return v;
        }
        return '';
    }

    function getCpfInputs() {
        return $('input#billing_cpf, input[name="billing_cpf"]');
    }

    function normalizeCpfDigits(value) {
        return (value || '').toString().replace(/[^0-9]/g, '');
    }

    function getCpfInput() {
        // Prefer visible/enabled inputs first (FunnelKit can re-render fields)
        var $inputs = getCpfInputs().filter(':visible').filter(function() {
            return !$(this).prop('disabled');
        });
        if ($inputs.length) return $inputs.first();

        $inputs = getCpfInputs();
        return $inputs.first();
    }

    function ensureSingleCpfInput() {
        var $all = getCpfInputs();
        if ($all.length <= 1) return;

        // Keep the best candidate, remove the rest (prefer one with value / matches persisted CPF)
        var $keep = $all.filter(function() {
            var v = normalizeCpfDigits($(this).val());
            return v.length > 0;
        }).first();

        if (!$keep.length && lastValidatedCpf) {
            $keep = $all.filter(function() {
                var v = normalizeCpfDigits($(this).val());
                return v === lastValidatedCpf;
            }).first();
        }

        if (!$keep.length) {
            $keep = $('input#billing_cpf:visible').first();
        }
        if (!$keep.length) $keep = $all.filter(':visible').first();
        if (!$keep.length) $keep = $all.first();

        $all.not($keep).each(function() {
            // Remove wrapper row if possible
            var $row = $(this).closest('.form-row, p, div');
            if ($row.length && $row.find('input[name="billing_cpf"]').length === 1) {
                $row.remove();
            } else {
                $(this).remove();
            }
        });
    }

    function injectCpfFieldIfMissing() {
        ensureSingleCpfInput();
        if (getCpfInput().length) return;

        var label = (wcCpfValidator && wcCpfValidator.fieldLabel) ? wcCpfValidator.fieldLabel : 'CPF';
        var placeholder = (wcCpfValidator && wcCpfValidator.fieldPlaceholder) ? wcCpfValidator.fieldPlaceholder : '000.000.000-00';
        var required = !!(wcCpfValidator && wcCpfValidator.required);

        var $emailRow = $('#billing_email_field').first();
        var $form = $('form.checkout').first();
        if (!$form.length) {
            // FunnelKit wrappers often still include form.checkout; if not found, bail.
            return;
        }

        var $row = $(
            '<p class="form-row form-row-wide wc-cpf-validator-input" id="billing_cpf_field">' +
                '<label for="billing_cpf_generated">' + label + (required ? ' <abbr class="required" title="obrigatório">*</abbr>' : '') + '</label>' +
                '<span class="woocommerce-input-wrapper">' +
                    '<input type="text" class="input-text" name="billing_cpf" id="billing_cpf_generated" placeholder="' + placeholder + '" maxlength="14" autocomplete="off" />' +
                '</span>' +
                '<div class="wc-cpf-validator-message" style="display:none;"></div>' +
            '</p>'
        );

        if ($emailRow.length) {
            $row.insertAfter($emailRow);
        } else {
            // Fallback: append near top
            $form.find('.woocommerce-billing-fields__field-wrapper').first().append($row);
            if (!$row.parent().length) {
                $form.append($row);
            }
        }
    }

    function getCpfRowAndMessage($cpfInput) {
        var $row = $cpfInput.closest('.form-row');
        if (!$row.length) {
            $row = $cpfInput.closest('p, div');
        }
        if ($row.length && !$row.hasClass('wc-cpf-validator-input')) {
            $row.addClass('wc-cpf-validator-input');
        }

        var $message = $row.find('.wc-cpf-validator-message');
        if (!$message.length) {
            $message = $('<div class="wc-cpf-validator-message" style="display:none;"></div>');
            if ($row.length) {
                $row.append($message);
            } else {
                $cpfInput.after($message);
            }
        }

        return { $row: $row, $message: $message };
    }

    /**
     * Show message
     */
    function showMessage($message, message, type) {
        $message.removeClass('success error info').addClass(type);
        $message.html(message).slideDown(200);
    }

    /**
     * Hide message
     */
    function hideMessage($message) {
        $message.slideUp(200);
    }

    /**
     * Validate CPF via AJAX
     */
    function validateCPF($row, $message, cpf) {
        // Remove formatting
        var cleanCPF = normalizeCpfDigits(cpf);

        // Check if CPF has 11 digits
        if (cleanCPF.length !== 11) {
            hideMessage($message);
            return;
        }

        // Avoid re-validating the same CPF (saves API credits)
        // Only skip if we already validated successfully (invalid can be retried; backend cache still protects credits).
        if (lastValidatedCpf === cleanCPF && lastValidationSuccess === true) {
            if ($row.length) {
                $row.addClass('valid').removeClass('invalid');
            }
            return;
        }

        // Prevent multiple simultaneous validations
        if (isValidating) return;

        isValidating = true;
        showMessage($message, wcCpfValidator.validating, 'info');

        // Make AJAX request
        // Abort any pending request (only affects UX; backend cache protects credits).
        try {
            if (activeAjax && activeAjax.readyState !== 4) {
                activeAjax.abort();
            }
        } catch (e) {}

        activeAjax = $.ajax({
            url: wcCpfValidator.ajax_url,
            type: 'POST',
            data: {
                action: 'validate_cpf',
                cpf: cpf,
                nonce: wcCpfValidator.nonce
            },
            success: function(response) {
                isValidating = false;

                if (response.success) {
                    lastValidatedCpf = cleanCPF;
                    lastValidationSuccess = true;
                    lastValidatedCpfMasked = cpf;
                    persistState();

                    showMessage($message, response.data.message, 'success');
                    
                    // Add visual feedback to input
                    if ($row.length) {
                        $row.addClass('valid').removeClass('invalid');
                    }

                    // Auto-fill first/last name when API returns full name (Plano "Nome Completo")
                    try {
                        var apiData = (response.data && response.data.data) ? response.data.data : {};
                        var fullName = extractFullName(apiData);
                        fullName = (fullName || '').toString().trim().replace(/\s+/g, ' ');

                        if (fullName.length) {
                            var parts = fullName.split(' ');
                            var firstName = parts.shift() || '';
                            var lastName = parts.join(' ').trim();
                            window.wcCpfValidatorFirstName = firstName;
                            window.wcCpfValidatorLastName = lastName;
                            persistState();
                            fillNameFields(firstName, lastName);
                        }
                    } catch (e) {
                        // Silent fail: auto-fill is best-effort only
                    }

                    // Lock CPF field for this checkout session (readonly so it still submits)
                    try {
                        var $cpfInput = getCpfInput();
                        if ($cpfInput.length) {
                            $cpfInput.prop('readonly', true).addClass('wc-cpf-validator-locked');
                        }
                    } catch (e2) {}
                } else {
                    lastValidatedCpf = cleanCPF;
                    lastValidationSuccess = false;
                    lastValidatedCpfMasked = null;
                    persistState();

                    showMessage($message, response.data.message, 'error');
                    
                    // Add visual feedback to input
                    if ($row.length) {
                        $row.addClass('invalid').removeClass('valid');
                    }
                }
            },
            error: function() {
                isValidating = false;
                lastValidatedCpf = cleanCPF;
                lastValidationSuccess = false;
                lastValidatedCpfMasked = null;
                persistState();
                showMessage($message, wcCpfValidator.invalid, 'error');
                if ($row.length) {
                    $row.addClass('invalid').removeClass('valid');
                }
            }
        });
    }

    /**
     * Validate CPF format (client-side)
     */
    function validateCPFFormat(cpf) {
        cpf = cpf.replace(/[^0-9]/g, '');

        if (cpf.length !== 11) {
            return false;
        }

        // Check if all digits are the same
        if (/^(\d)\1+$/.test(cpf)) {
            return false;
        }

        // Validate CPF algorithm
        var sum = 0;
        var remainder;

        for (var i = 1; i <= 9; i++) {
            sum += parseInt(cpf.substring(i - 1, i)) * (11 - i);
        }

        remainder = (sum * 10) % 11;

        if ((remainder === 10) || (remainder === 11)) {
            remainder = 0;
        }

        if (remainder !== parseInt(cpf.substring(9, 10))) {
            return false;
        }

        sum = 0;
        for (i = 1; i <= 10; i++) {
            sum += parseInt(cpf.substring(i - 1, i)) * (12 - i);
        }

        remainder = (sum * 10) % 11;

        if ((remainder === 10) || (remainder === 11)) {
            remainder = 0;
        }

        if (remainder !== parseInt(cpf.substring(10, 11))) {
            return false;
        }

        return true;
    }

    /**
     * Handle CPF input
     */
    function setupCpfField() {
        injectCpfFieldIfMissing();
        ensureSingleCpfInput();

        var $cpfInput = getCpfInput();
        if (!$cpfInput.length) {
            return;
        }

        var ui = getCpfRowAndMessage($cpfInput);
        var $row = ui.$row;
        var $message = ui.$message;

        // Apply CPF mask once
        if (!$cpfInput.data('wcCpfValidatorMasked')) {
            if ($.fn && $.fn.mask) {
                $cpfInput.mask('000.000.000-00');
            }
            $cpfInput.data('wcCpfValidatorMasked', true);
        }

        // Re-apply lock if already validated successfully
        var currentClean = normalizeCpfDigits($cpfInput.val());
        if (lastValidatedCpf && lastValidationSuccess === true) {
            // FunnelKit can recreate the field and wipe the value; restore it.
            if (!currentClean.length && lastValidatedCpfMasked) {
                $cpfInput.val(lastValidatedCpfMasked).trigger('input').trigger('change');
                currentClean = lastValidatedCpf;
            }
            if (currentClean === lastValidatedCpf) {
                $cpfInput.prop('readonly', true).addClass('wc-cpf-validator-locked');
                if ($row.length) {
                    $row.addClass('valid').removeClass('invalid');
                }
                // Restore name fields too (FunnelKit can wipe them on re-render)
                restoreNameFieldsIfAvailable();
            }
        }
    }

    function bindDelegatedHandlersOnce() {
        if (handlersBound) return;
        handlersBound = true;

        // Delegate events so it keeps working after FunnelKit replaces inputs.
        $(document.body).on('input.wcCpfValidator blur.wcCpfValidator change.wcCpfValidator', 'input#billing_cpf, input[name="billing_cpf"]', function(e) {
            var $cpfInput = $(this);
            var ui = getCpfRowAndMessage($cpfInput);
            var $row = ui.$row;
            var $message = ui.$message;

            // Apply mask if needed (FunnelKit may recreate input)
            if (!$cpfInput.data('wcCpfValidatorMasked')) {
                if ($.fn && $.fn.mask) {
                    $cpfInput.mask('000.000.000-00');
                }
                $cpfInput.data('wcCpfValidatorMasked', true);
            }

            var cpf = $cpfInput.val();
            var clean = normalizeCpfDigits(cpf);

            // Clear previous timeout
            if (validationTimeout) {
                clearTimeout(validationTimeout);
            }

            // If locked, do nothing further
            if ($cpfInput.prop('readonly')) {
                return;
            }

            // If CPF changed compared to last validated, allow new validation attempts.
            if (lastValidatedCpf && clean && clean !== lastValidatedCpf) {
                lastValidationSuccess = null;
            }

            // Visual reset on typing
            if (e.type === 'input') {
                if ($row.length) $row.removeClass('valid invalid');
                hideMessage($message);
            }

            if (!cpf || cpf.length === 0) {
                return;
            }

            // Check format first (client-side)
            if (!validateCPFFormat(cpf)) {
                if (clean.length === 11) {
                    showMessage($message, wcCpfValidator.invalid, 'error');
                    if ($row.length) $row.addClass('invalid');
                }
                return;
            }

            // Real-time mode: debounce on input
            if (wcCpfValidator.realtime) {
                // If user just completed 11 digits, validate immediately (faster UX).
                if (e.type === 'input' && clean.length === 11) {
                    validateCPF($row, $message, cpf);
                    return;
                }

                if (e.type === 'input') {
                    // shorter debounce for better perceived speed
                    validationTimeout = setTimeout(function() {
                        validateCPF($row, $message, cpf);
                    }, 300);
                    return;
                }
                // On blur/change, validate immediately (otherwise leaving field cancels debounce).
                if (e.type === 'blur' || e.type === 'change') {
                    validateCPF($row, $message, cpf);
                }
                return;
            }

            // Non real-time: validate once on blur OR change (FunnelKit often triggers change instead of blur)
            if (e.type === 'blur' || e.type === 'change') {
                validateCPF($row, $message, cpf);
            }
        });

        // Trigger checkout update when CPF changes (delegated)
        $(document.body).on('change.wcCpfValidatorCheckout', 'input#billing_cpf, input[name="billing_cpf"]', function() {
            $('body').trigger('update_checkout');
        });
    }

    // Initial setup + re-setup after checkout fragments update (field may be inserted/removed by other plugins)
    bindDelegatedHandlersOnce();
    setupCpfField();
    $(document.body).on('updated_checkout.wcCpfValidator', setupCpfField);
    $(document.body).on('init_checkout.wcCpfValidator', setupCpfField);

    // FunnelKit can trigger its own refresh events; listen broadly.
    $(document.body).on('wfacp_updated_checkout.wcCpfValidator wfacp_step_change.wcCpfValidator wfacp_step_changed.wcCpfValidator', setupCpfField);

    // MutationObserver fallback: if the CPF input appears/replaces, setup again.
    try {
        var obs = new MutationObserver(function(mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type === 'childList' && (m.addedNodes && m.addedNodes.length)) {
                    for (var j = 0; j < m.addedNodes.length; j++) {
                        var node = m.addedNodes[j];
                        if (!node || !node.querySelector) continue;
                        if (node.querySelector('input#billing_cpf, input[name="billing_cpf"]')) {
                            setupCpfField();
                            return;
                        }
                    }
                }
            }
        });
        obs.observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
});
