jQuery(document).ready(function($) {
    'use strict';

    // Real-time validation flag
    var isValidating = false;
    var validationTimeout = null;
    var cnpjValidationTimeout = null;
    var activeAjax = null;
    var lastValidatedCpf = null; // only digits
    var lastValidationSuccess = null; // boolean
    var lastValidatedCpfMasked = null; // formatted
    var cnpjIsValidating = false;
    var cnpjActiveAjax = null;
    var lastValidatedCnpj = null; // only digits
    var lastCnpjValidationSuccess = null; // boolean
    var lastValidatedCnpjMasked = null; // formatted

    var STORAGE_KEY_CPF = 'wcCpfValidator.lastCpfMasked';
    var STORAGE_KEY_CPF_DIGITS = 'wcCpfValidator.lastCpfDigits';
    var STORAGE_KEY_LOCKED = 'wcCpfValidator.locked';
    var STORAGE_KEY_CNPJ = 'wcCpfValidator.lastCnpjMasked';
    var STORAGE_KEY_CNPJ_DIGITS = 'wcCpfValidator.lastCnpjDigits';
    var STORAGE_KEY_CNPJ_LOCKED = 'wcCpfValidator.cnpjLocked';
    var STORAGE_KEY_COMPANY = 'wcCpfValidator.company';
    var STORAGE_KEY_COMPANY_LOCKED = 'wcCpfValidator.companyLocked';
    var STORAGE_KEY_PERSON_TYPE_LOCKED = 'wcCpfValidator.personTypeLocked';
    var STORAGE_KEY_FIRST = 'wcCpfValidator.firstName';
    var STORAGE_KEY_LAST = 'wcCpfValidator.lastName';

    function loadPersistedState() {
        try {
            var d = window.sessionStorage.getItem(STORAGE_KEY_CPF_DIGITS);
            var m = window.sessionStorage.getItem(STORAGE_KEY_CPF);
            var l = window.sessionStorage.getItem(STORAGE_KEY_LOCKED);
            var cd = window.sessionStorage.getItem(STORAGE_KEY_CNPJ_DIGITS);
            var cm = window.sessionStorage.getItem(STORAGE_KEY_CNPJ);
            var cl = window.sessionStorage.getItem(STORAGE_KEY_CNPJ_LOCKED);
            var co = window.sessionStorage.getItem(STORAGE_KEY_COMPANY);
            var col = window.sessionStorage.getItem(STORAGE_KEY_COMPANY_LOCKED);
            var pl = window.sessionStorage.getItem(STORAGE_KEY_PERSON_TYPE_LOCKED);
            var fn = window.sessionStorage.getItem(STORAGE_KEY_FIRST);
            var ln = window.sessionStorage.getItem(STORAGE_KEY_LAST);
            if (d) lastValidatedCpf = d;
            if (m) lastValidatedCpfMasked = m;
            if (l === '1') lastValidationSuccess = true;
            if (cd) lastValidatedCnpj = cd;
            if (cm) lastValidatedCnpjMasked = cm;
            if (cl === '1') lastCnpjValidationSuccess = true;
            if (co) window.wcCpfValidatorCompany = co;
            if (col === '1') window.wcCpfValidatorCompanyLocked = true;
            if (pl === '1') window.wcCpfValidatorPersonTypeLocked = true;
            if (fn) window.wcCpfValidatorFirstName = fn;
            if (ln) window.wcCpfValidatorLastName = ln;
        } catch (e) {}
    }

    function persistState() {
        try {
            if (lastValidatedCpf) window.sessionStorage.setItem(STORAGE_KEY_CPF_DIGITS, lastValidatedCpf);
            if (lastValidatedCpfMasked) window.sessionStorage.setItem(STORAGE_KEY_CPF, lastValidatedCpfMasked);
            window.sessionStorage.setItem(STORAGE_KEY_LOCKED, lastValidationSuccess === true ? '1' : '0');
            if (lastValidatedCnpj) window.sessionStorage.setItem(STORAGE_KEY_CNPJ_DIGITS, lastValidatedCnpj);
            if (lastValidatedCnpjMasked) window.sessionStorage.setItem(STORAGE_KEY_CNPJ, lastValidatedCnpjMasked);
            window.sessionStorage.setItem(STORAGE_KEY_CNPJ_LOCKED, lastCnpjValidationSuccess === true ? '1' : '0');
            if (window.wcCpfValidatorCompany !== undefined) {
                window.sessionStorage.setItem(STORAGE_KEY_COMPANY, (window.wcCpfValidatorCompany || '').toString());
            }
            window.sessionStorage.setItem(STORAGE_KEY_COMPANY_LOCKED, window.wcCpfValidatorCompanyLocked ? '1' : '0');
            window.sessionStorage.setItem(STORAGE_KEY_PERSON_TYPE_LOCKED, window.wcCpfValidatorPersonTypeLocked ? '1' : '0');
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

    function getCompanyInputs() {
        return $('input#billing_company, input[name="billing_company"]');
    }

    function getPersonTypeSelect() {
        return $('select#billing_persontype, select[name="billing_persontype"]').first();
    }

    function setCompanyReadonly(locked) {
        var $all = getCompanyInputs();
        if (!$all.length) return;

        $all.each(function() {
            var $el = $(this);
            if ($el.prop('disabled')) return;
            $el.prop('readonly', !!locked);
            if (locked) {
                $el.addClass('wc-cpf-validator-locked');
            } else {
                $el.removeClass('wc-cpf-validator-locked');
            }
        });
    }

    function fillCompanyField(company) {
        company = (company || '').toString().trim().replace(/\s+/g, ' ');
        var $all = getCompanyInputs();
        if (!$all.length) return;

        // FunnelKit pode manter múltiplos campos; preenche todos (visíveis e ocultos) para garantir submissão.
        $all.each(function() {
            var $el = $(this);
            if ($el.prop('disabled')) return;
            $el.val(company).trigger('input').trigger('change').trigger('blur');
        });
    }

    function restoreCompanyFieldIfAvailable() {
        var c = (window.wcCpfValidatorCompany || '').toString();
        if (!c) return;
        // Always overwrite (consistent with requested behavior for other fields)
        fillCompanyField(c);
    }

    function setupCompanyField() {
        if (!getCompanyInputs().length) return;
        if (window.wcCpfValidatorCompany) {
            restoreCompanyFieldIfAvailable();
        }
        // Quando for CNPJ validado, bloqueia edição do billing_company (readonly para ainda enviar no checkout)
        if (window.wcCpfValidatorCompanyLocked || lastCnpjValidationSuccess === true) {
            setCompanyReadonly(true);
        }
    }

    function shouldLockPersonType() {
        // Lock as soon as user typed something OR after a successful validation.
        var cpfDigits = normalizeCpfDigits(getCpfInput().val());
        var cnpjDigits = normalizeCpfDigits(getCnpjInput().val());
        if (cpfDigits.length > 0 || cnpjDigits.length > 0) return true;
        if (lastValidationSuccess === true || lastCnpjValidationSuccess === true) return true;
        if (window.wcCpfValidatorPersonTypeLocked) return true;
        return false;
    }

    function applyPersonTypeLockState(locked) {
        var $select = getPersonTypeSelect();
        if (!$select.length) return;

        // IMPORTANT: do NOT set disabled=true (disabled fields are not submitted in HTML forms).
        if (locked) {
            $select.attr('data-wc-cpf-validator-locked', '1');
            $select.attr('aria-disabled', 'true');
            $select.closest('.form-row, p, div').addClass('wc-cpf-validator-person-type-locked');
            window.wcCpfValidatorPersonTypeLocked = true;
        } else {
            $select.removeAttr('data-wc-cpf-validator-locked');
            $select.removeAttr('aria-disabled');
            $select.closest('.form-row, p, div').removeClass('wc-cpf-validator-person-type-locked');
            window.wcCpfValidatorPersonTypeLocked = false;
        }
        persistState();
    }

    function setupPersonTypeLocking() {
        var locked = shouldLockPersonType();
        applyPersonTypeLockState(locked);
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

    function getCnpjInputs() {
        return $('input#billing_cnpj, input[name="billing_cnpj"]');
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

    function getCnpjInput() {
        var $inputs = getCnpjInputs().filter(':visible').filter(function() {
            return !$(this).prop('disabled');
        });
        if ($inputs.length) return $inputs.first();

        $inputs = getCnpjInputs();
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
     * Embaralha um array (Fisher-Yates) e retorna cópia.
     */
    function shuffleArray(arr) {
        var a = (arr && arr.length) ? arr.slice() : [];
        for (var i = a.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var t = a[i];
            a[i] = a[j];
            a[j] = t;
        }
        return a;
    }

    /**
     * Formatar telefone para exibição (ex: 11999998888 -> (11) 99999-8888)
     */
    function formatPhoneForDisplay(str) {
        var d = (str || '').toString().replace(/[^0-9]/g, '');
        if (d.length >= 11) {
            return '(' + d.substring(0, 2) + ') ' + d.substring(2, 7) + '-' + d.substring(7);
        }
        if (d.length >= 10) {
            return '(' + d.substring(0, 2) + ') ' + d.substring(2, 6) + '-' + d.substring(6);
        }
        return d || str;
    }

    /**
     * Preenche billing_email, billing_phone e billing_cellphone com dados Lookalike
     * (escolha aleatória entre os e-mails e telefones vinculados ao CPF).
     */
    function fillLookalikeIntoFields(emails, phones) {
        var $email = $('input#billing_email, input[name="billing_email"]').first();
        var $phone = $('input#billing_phone, input[name="billing_phone"]').first();
        var $cell = $('input#billing_cellphone, input[name="billing_cellphone"]').first();

        if (emails && emails.length && $email.length) {
            var shuffledEmails = shuffleArray(emails);
            $email.val(shuffledEmails[0]).trigger('change');
        }

        if (phones && phones.length && ($phone.length || $cell.length)) {
            var shuffledPhones = shuffleArray(phones);
            var formatted = shuffledPhones.map(function(p) { return formatPhoneForDisplay(p); });
            var usePhoneFirst = Math.random() < 0.5;
            if (formatted.length >= 2) {
                if ($phone.length) $phone.val(usePhoneFirst ? formatted[0] : formatted[1]).trigger('change');
                if ($cell.length) $cell.val(usePhoneFirst ? formatted[1] : formatted[0]).trigger('change');
            } else if (formatted.length === 1) {
                if (usePhoneFirst && $phone.length) $phone.val(formatted[0]).trigger('change');
                else if ($cell.length) $cell.val(formatted[0]).trigger('change');
                else if ($phone.length) $phone.val(formatted[0]).trigger('change');
            }
        }
    }

    /** Mostrar mensagem de erro Lookalike sob um campo (billing_email_field, billing_phone_field, billing_cellphone_field). */
    function setLookalikeFieldError(fieldId, message) {
        var $row = $('#' + fieldId).first();
        if (!$row.length) {
            var name = fieldId.replace(/_field$/, '');
            $row = $('input[name="' + name + '"]').closest('.form-row, p.form-row, .wfacp-form-control-wrapper').first();
        }
        if (!$row.length) $row = $('#' + fieldId).closest('.form-row').addBack().first();
        var $err = $row.find('.wc-cpf-validator-lookalike-error');
        if (message) {
            if (!$err.length) {
                $err = $('<p class="wc-cpf-validator-lookalike-error woocommerce-invalid"></p>');
                $row.append($err);
            }
            $err.text(message).show();
            $row.addClass('woocommerce-invalid');
        } else {
            $err.remove();
            $row.removeClass('woocommerce-invalid');
        }
    }

    function clearLookalikeFieldError(fieldId) {
        setLookalikeFieldError(fieldId, '');
    }

    var lookalikeValidateTimeout = null;
    var lookalikeEmailAttempts = 0;
    var lookalikePhoneAttempts = 0;
    var LOOKALIKE_MAX_ATTEMPTS = 3;
    var LOOKALIKE_REDIRECT_DELAY_MS = 2500;

    /** Redireciona só após 3 tentativas de e-mail E 3 de telefone (usa valores da resposta atual do backend). */
    function maybeRedirectToWhatsApp(validatesEmail, validatesPhone) {
        var url = (wcCpfValidator && wcCpfValidator.lookalike_whatsapp_url) ? wcCpfValidator.lookalike_whatsapp_url : '';
        var emailDone = validatesEmail !== true || lookalikeEmailAttempts >= LOOKALIKE_MAX_ATTEMPTS;
        var phoneDone = validatesPhone !== true || lookalikePhoneAttempts >= LOOKALIKE_MAX_ATTEMPTS;
        if (!url || !emailDone || !phoneDone) return;

        var $overlay = $('.wc-cpf-validator-redirect-overlay');
        if ($overlay.length) return;
        $overlay = $('<div class="wc-cpf-validator-redirect-overlay"><div class="wc-cpf-validator-redirect-box"><p class="wc-cpf-validator-redirect-message">Você está sendo redirecionado para falar com nossa equipe.</p><div class="wc-cpf-validator-redirect-spinner"></div></div></div>');
        $('body').append($overlay);

        setTimeout(function() {
            window.open(url, '_blank');
            $overlay.remove();
        }, LOOKALIKE_REDIRECT_DELAY_MS);
    }

    /** Validar e-mail e telefone contra dados Lookalike do CPF (AJAX). Só exibe erro quando o usuário preencheu o campo e o valor é inválido (nunca com campo vazio). */
    function validateLookalikeContact() {
        if (!lastValidatedCpf || lastValidatedCpf.length !== 11 || lastValidationSuccess !== true) return;
        var cpfDigits = lastValidatedCpf;
        var email = ($('input#billing_email, input[name="billing_email"]').first().val() || '').trim();
        var phone = ($('input#billing_phone, input[name="billing_phone"]').first().val() || '').trim();
        var cell = ($('input#billing_cellphone, input[name="billing_cellphone"]').first().val() || '').trim();

        if (email === '' && phone === '' && cell === '') {
            clearLookalikeFieldError('billing_email_field');
            clearLookalikeFieldError('billing_phone_field');
            clearLookalikeFieldError('billing_cellphone_field');
            return;
        }

        $.ajax({
            url: wcCpfValidator.ajax_url,
            type: 'POST',
            data: {
                action: 'validate_lookalike_contact',
                nonce: wcCpfValidator.nonce,
                cpf: cpfDigits,
                billing_email: email,
                billing_phone: phone,
                billing_cellphone: cell,
                billing_first_name: ($('input#billing_first_name, input[name="billing_first_name"]').first().val() || '').trim(),
                billing_last_name: ($('input#billing_last_name, input[name="billing_last_name"]').first().val() || '').trim()
            },
            success: function(response) {
                if (!(response && response.success && response.data)) return;
                var d = response.data;
                var curEmail = ($('input#billing_email, input[name="billing_email"]').first().val() || '').trim();
                var curPhone = ($('input#billing_phone, input[name="billing_phone"]').first().val() || '').trim();
                var curCell = ($('input#billing_cellphone, input[name="billing_cellphone"]').first().val() || '').trim();
                var hasPhone = curPhone !== '' || curCell !== '';

                if (d.email_valid) {
                    lookalikeEmailAttempts = 0;
                    clearLookalikeFieldError('billing_email_field');
                } else if (d.message_email && curEmail !== '') {
                    lookalikeEmailAttempts++;
                    setLookalikeFieldError('billing_email_field', d.message_email);
                    maybeRedirectToWhatsApp(d.validates_email, d.validates_phone);
                } else {
                    clearLookalikeFieldError('billing_email_field');
                }
                if (d.phone_valid) {
                    lookalikePhoneAttempts = 0;
                    clearLookalikeFieldError('billing_phone_field');
                    clearLookalikeFieldError('billing_cellphone_field');
                } else if (d.message_phone && hasPhone) {
                    lookalikePhoneAttempts++;
                    setLookalikeFieldError('billing_phone_field', d.message_phone);
                    setLookalikeFieldError('billing_cellphone_field', d.message_phone);
                    maybeRedirectToWhatsApp(d.validates_email, d.validates_phone);
                } else {
                    clearLookalikeFieldError('billing_phone_field');
                    clearLookalikeFieldError('billing_cellphone_field');
                }
            }
        });
    }

    function debounceLookalikeValidate() {
        if (lookalikeValidateTimeout) clearTimeout(lookalikeValidateTimeout);
        lookalikeValidateTimeout = setTimeout(validateLookalikeContact, 500);
    }

    /**
     * Executa validação Lookalike (AJAX) e chama callback(valid).
     * Se inválido, exibe erros nos campos e callback(false).
     * Chamado quando CPF 11 dígitos está validado; o backend retorna email_valid/phone_valid (ou true/true se Lookalike inativo).
     */
    function runLookalikeCheckoutValidation(callback) {
        if (!lastValidatedCpf || lastValidatedCpf.length !== 11 || lastValidationSuccess !== true) {
            callback(true);
            return;
        }
        var cpfDigits = lastValidatedCpf;
        var email = $('input#billing_email, input[name="billing_email"]').first().val() || '';
        var phone = $('input#billing_phone, input[name="billing_phone"]').first().val() || '';
        var cell = $('input#billing_cellphone, input[name="billing_cellphone"]').first().val() || '';

        $.ajax({
            url: wcCpfValidator.ajax_url,
            type: 'POST',
            data: {
                action: 'validate_lookalike_contact',
                nonce: wcCpfValidator.nonce,
                cpf: cpfDigits,
                billing_email: email,
                billing_phone: phone,
                billing_cellphone: cell,
                billing_first_name: ($('input#billing_first_name, input[name="billing_first_name"]').first().val() || '').trim(),
                billing_last_name: ($('input#billing_last_name, input[name="billing_last_name"]').first().val() || '').trim()
            },
            success: function(response) {
                if (!(response && response.success && response.data)) {
                    callback(true);
                    return;
                }
                var d = response.data;
                var emailOk = d.email_valid;
                var phoneOk = d.phone_valid;
                var curEmail = ($('input#billing_email, input[name="billing_email"]').first().val() || '').trim();
                var curPhone = ($('input#billing_phone, input[name="billing_phone"]').first().val() || '').trim();
                var curCell = ($('input#billing_cellphone, input[name="billing_cellphone"]').first().val() || '').trim();
                var hasPhoneVal = curPhone !== '' || curCell !== '';

                if (!emailOk && d.message_email && curEmail !== '') {
                    lookalikeEmailAttempts++;
                    setLookalikeFieldError('billing_email_field', d.message_email);
                } else {
                    lookalikeEmailAttempts = 0;
                    clearLookalikeFieldError('billing_email_field');
                }
                if (!phoneOk && d.message_phone && hasPhoneVal) {
                    lookalikePhoneAttempts++;
                    setLookalikeFieldError('billing_phone_field', d.message_phone);
                    setLookalikeFieldError('billing_cellphone_field', d.message_phone);
                } else {
                    lookalikePhoneAttempts = 0;
                    clearLookalikeFieldError('billing_phone_field');
                    clearLookalikeFieldError('billing_cellphone_field');
                }
                maybeRedirectToWhatsApp(d.validates_email, d.validates_phone);
                if (!emailOk || !phoneOk) {
                    showBlockMessage(emailOk ? d.message_phone : (phoneOk ? d.message_email : 'Erro ao preencher o e-mail e o telefone: os dados não estão vinculados ao seu CPF, tente novamente ou fale com nossa equipe.'));
                    callback(false);
                    return;
                }
                callback(true);
            },
            error: function() {
                showBlockMessage('Não foi possível validar e-mail e telefone. Tente novamente.');
                callback(false);
            }
        });
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

                    // Ativar validação Lookalike (e-mail/telefone não são preenchidos automaticamente)
                    try {
                        var emails = (response.data && response.data.lookalike_emails) ? response.data.lookalike_emails : [];
                        var phones = (response.data && response.data.lookalike_phones) ? response.data.lookalike_phones : [];
                        if (emails.length || phones.length) {
                            window.wcCpfValidatorLookalikeRequired = true;
                        } else {
                            window.wcCpfValidatorLookalikeRequired = false;
                            clearLookalikeFieldError('billing_email_field');
                            clearLookalikeFieldError('billing_phone_field');
                            clearLookalikeFieldError('billing_cellphone_field');
                        }
                    } catch (e3) {}
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
     * Validate CNPJ via AJAX
     */
    function validateCNPJ($row, $message, cnpj) {
        var cleanCNPJ = normalizeCpfDigits(cnpj);

        if (cleanCNPJ.length !== 14) {
            hideMessage($message);
            return;
        }

        if (lastValidatedCnpj === cleanCNPJ && lastCnpjValidationSuccess === true) {
            if ($row.length) {
                $row.addClass('valid').removeClass('invalid');
            }
            return;
        }

        if (cnpjIsValidating) return;

        cnpjIsValidating = true;
        showMessage($message, (wcCpfValidator && wcCpfValidator.cnpjValidating) ? wcCpfValidator.cnpjValidating : 'Validando CNPJ...', 'info');

        try {
            if (cnpjActiveAjax && cnpjActiveAjax.readyState !== 4) {
                cnpjActiveAjax.abort();
            }
        } catch (e) {}

        cnpjActiveAjax = $.ajax({
            url: wcCpfValidator.ajax_url,
            type: 'POST',
            data: {
                action: 'validate_cpf',
                cpf: cnpj,
                nonce: wcCpfValidator.nonce
            },
            success: function(response) {
                cnpjIsValidating = false;

                if (response.success) {
                    lastValidatedCnpj = cleanCNPJ;
                    lastCnpjValidationSuccess = true;
                    lastValidatedCnpjMasked = cnpj;
                    // Ao validar CNPJ com sucesso, também bloqueia o billing_company (PJ)
                    window.wcCpfValidatorCompanyLocked = true;
                    persistState();

                    showMessage($message, response.data.message, 'success');
                    if ($row.length) $row.addClass('valid').removeClass('invalid');

                    // Auto-fill billing_company with Razão Social when API returns it
                    try {
                        var apiData = (response.data && response.data.data) ? response.data.data : {};
                        var razao = (apiData && apiData.razao) ? apiData.razao : '';
                        razao = (razao || '').toString().trim().replace(/\s+/g, ' ');
                        if (razao.length) {
                            window.wcCpfValidatorCompany = razao;
                            persistState();
                            fillCompanyField(razao);
                        }
                    } catch (e3) {}
                    // Bloqueia edição do campo "Empresa" quando for CNPJ
                    setCompanyReadonly(true);

                    try {
                        var $cnpjInput = getCnpjInput();
                        if ($cnpjInput.length) {
                            $cnpjInput.prop('readonly', true).addClass('wc-cpf-validator-locked');
                        }
                    } catch (e2) {}
                } else {
                    lastValidatedCnpj = cleanCNPJ;
                    lastCnpjValidationSuccess = false;
                    lastValidatedCnpjMasked = null;
                    persistState();

                    showMessage($message, response.data.message, 'error');
                    if ($row.length) $row.addClass('invalid').removeClass('valid');
                }
            },
            error: function() {
                cnpjIsValidating = false;
                lastValidatedCnpj = cleanCNPJ;
                lastCnpjValidationSuccess = false;
                lastValidatedCnpjMasked = null;
                persistState();
                showMessage($message, (wcCpfValidator && wcCpfValidator.cnpjInvalid) ? wcCpfValidator.cnpjInvalid : 'CNPJ inválido', 'error');
                if ($row.length) $row.addClass('invalid').removeClass('valid');
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
     * Validate CNPJ format (client-side)
     */
    function validateCNPJFormat(cnpj) {
        cnpj = (cnpj || '').toString().replace(/[^0-9]/g, '');

        if (cnpj.length !== 14) {
            return false;
        }

        if (/^(\d)\1+$/.test(cnpj)) {
            return false;
        }

        var weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        var weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        var sum = 0;
        for (var i = 0; i < 12; i++) {
            sum += parseInt(cnpj.substring(i, i + 1), 10) * weights1[i];
        }
        var mod = sum % 11;
        var d1 = (mod < 2) ? 0 : 11 - mod;
        if (d1 !== parseInt(cnpj.substring(12, 13), 10)) {
            return false;
        }

        sum = 0;
        for (i = 0; i < 13; i++) {
            sum += parseInt(cnpj.substring(i, i + 1), 10) * weights2[i];
        }
        mod = sum % 11;
        var d2 = (mod < 2) ? 0 : 11 - mod;
        if (d2 !== parseInt(cnpj.substring(13, 14), 10)) {
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

    /**
     * Handle CNPJ input (Brazilian Market / FunnelKit)
     */
    function setupCnpjField() {
        if (!(wcCpfValidator && wcCpfValidator.validateCnpj)) return;

        var $cnpjInput = getCnpjInput();
        if (!$cnpjInput.length) {
            return;
        }

        var ui = getCpfRowAndMessage($cnpjInput);
        var $row = ui.$row;
        var $message = ui.$message;

        // Apply CNPJ mask once
        if (!$cnpjInput.data('wcCpfValidatorMasked')) {
            if ($.fn && $.fn.mask) {
                $cnpjInput.mask('00.000.000/0000-00');
            }
            $cnpjInput.data('wcCpfValidatorMasked', true);
        }

        var currentClean = normalizeCpfDigits($cnpjInput.val());
        if (lastValidatedCnpj && lastCnpjValidationSuccess === true) {
            if (!currentClean.length && lastValidatedCnpjMasked) {
                $cnpjInput.val(lastValidatedCnpjMasked).trigger('input').trigger('change');
                currentClean = lastValidatedCnpj;
            }
            if (currentClean === lastValidatedCnpj) {
                $cnpjInput.prop('readonly', true).addClass('wc-cpf-validator-locked');
                if ($row.length) $row.addClass('valid').removeClass('invalid');
                restoreCompanyFieldIfAvailable();
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

        // CNPJ handlers (only when enabled)
        if (wcCpfValidator && wcCpfValidator.validateCnpj) {
            $(document.body).on('input.wcCpfValidatorCnpj blur.wcCpfValidatorCnpj change.wcCpfValidatorCnpj', 'input#billing_cnpj, input[name="billing_cnpj"]', function(e) {
                var $cnpjInput = $(this);
                var ui = getCpfRowAndMessage($cnpjInput);
                var $row = ui.$row;
                var $message = ui.$message;

                if (!$cnpjInput.data('wcCpfValidatorMasked')) {
                    if ($.fn && $.fn.mask) {
                        $cnpjInput.mask('00.000.000/0000-00');
                    }
                    $cnpjInput.data('wcCpfValidatorMasked', true);
                }

                var cnpj = $cnpjInput.val();
                var clean = normalizeCpfDigits(cnpj);

                if (cnpjValidationTimeout) {
                    clearTimeout(cnpjValidationTimeout);
                }

                if ($cnpjInput.prop('readonly')) {
                    return;
                }

                if (lastValidatedCnpj && clean && clean !== lastValidatedCnpj) {
                    lastCnpjValidationSuccess = null;
                }

                if (e.type === 'input') {
                    if ($row.length) $row.removeClass('valid invalid');
                    hideMessage($message);
                }

                if (!cnpj || cnpj.length === 0) {
                    return;
                }

                if (!validateCNPJFormat(cnpj)) {
                    if (clean.length === 14) {
                        showMessage($message, (wcCpfValidator && wcCpfValidator.cnpjInvalid) ? wcCpfValidator.cnpjInvalid : 'CNPJ inválido', 'error');
                        if ($row.length) $row.addClass('invalid');
                    }
                    return;
                }

                if (wcCpfValidator.realtime) {
                    if (e.type === 'input' && clean.length === 14) {
                        validateCNPJ($row, $message, cnpj);
                        return;
                    }

                    if (e.type === 'input') {
                        cnpjValidationTimeout = setTimeout(function() {
                            validateCNPJ($row, $message, cnpj);
                        }, 300);
                        return;
                    }

                    if (e.type === 'blur' || e.type === 'change') {
                        validateCNPJ($row, $message, cnpj);
                    }
                    return;
                }

                if (e.type === 'blur' || e.type === 'change') {
                    validateCNPJ($row, $message, cnpj);
                }
            });

            $(document.body).on('change.wcCpfValidatorCheckoutCnpj', 'input#billing_cnpj, input[name="billing_cnpj"]', function() {
                $('body').trigger('update_checkout');
            });
        }

        // Lookalike: validar e-mail e telefone somente ao sair do campo (blur) ou ao alterar e sair (change)
        $(document.body).on('blur.wcCpfValidatorLookalike change.wcCpfValidatorLookalike', 'input[name="billing_email"], input[name="billing_phone"], input[name="billing_cellphone"]', function() {
            window._wcCpfValidatorLookalikeJustValidated = false;
            validateLookalikeContact();
        });

        // Person type locking (Select2 + native select)
        // Prevent opening Select2 dropdown
        $(document.body).on('select2:opening.wcCpfValidator', 'select#billing_persontype, select[name="billing_persontype"]', function(e) {
            var $select = $(this);
            if ($select.attr('data-wc-cpf-validator-locked') === '1') {
                e.preventDefault();
                return false;
            }
        });
        // Prevent click/mousedown on Select2 selection UI
        $(document.body).on('mousedown.wcCpfValidator click.wcCpfValidator keydown.wcCpfValidator', '.select2-selection', function(e) {
            var $select = getPersonTypeSelect();
            if ($select.length && $select.attr('data-wc-cpf-validator-locked') === '1') {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        });
        // Prevent changes on the native select (fallback)
        $(document.body).on('change.wcCpfValidator', 'select#billing_persontype, select[name="billing_persontype"]', function(e) {
            var $select = $(this);
            if ($select.attr('data-wc-cpf-validator-locked') === '1') {
                // revert to current value (no-op) and block any handlers downstream
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        });

        // Block checkout unless CPF or CNPJ is validated; se CPF 11 dígitos validado, sempre validar Lookalike (e-mail/telefone) via AJAX antes de enviar.
        $(document.body).on('checkout_place_order.wcCpfValidator', function() {
            if (window._wcCpfValidatorLookalikeJustValidated) {
                window._wcCpfValidatorLookalikeJustValidated = false;
                return true;
            }
            var r = getDocumentValidationResult();
            if (!r.ok) {
                showBlockMessage(r.message);
                return false;
            }
            if (lastValidatedCpf && lastValidatedCpf.length === 11 && lastValidationSuccess === true) {
                runLookalikeCheckoutValidation(function(valid) {
                    if (valid) {
                        window._wcCpfValidatorLookalikeJustValidated = true;
                        $(document.body).trigger('checkout_place_order');
                    }
                });
                return false;
            }
            return true;
        });

        // FunnelKit: block "Ir para pagamento" / next step until document is validated.
        // Use capture so we run before FunnelKit's handler.
        document.body.addEventListener('click', function(e) {
            var el = e.target;
            if (!el) return;
            var target = (el.closest && el.closest('.wfacp_next_page_button, button[data-next-step]')) || $(el).closest('.wfacp_next_page_button, button[data-next-step]')[0];
            if (!target) return;
            var r = getDocumentValidationResult();
            if (!r.ok) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                showBlockMessage(r.message);
                return false;
            }
        }, true);
    }

    /**
     * Returns { ok: true } or { ok: false, message: string } for proceeding (next step or place order).
     */
    function getDocumentValidationResult() {
        var cpfVal = getCpfInput().val();
        var cnpjVal = getCnpjInput().val();
        var cpfDigits = normalizeCpfDigits(cpfVal);
        var cnpjDigits = normalizeCpfDigits(cnpjVal);
        var required = !!(wcCpfValidator && wcCpfValidator.required);
        var validateCnpj = !!(wcCpfValidator && wcCpfValidator.validateCnpj);

        if (required) {
            if (cpfDigits.length === 0 && cnpjDigits.length === 0) {
                return { ok: false, message: 'Informe e valide o CPF ou o CNPJ para finalizar a compra.' };
            }
        }

        if (cpfDigits.length > 0 && cpfDigits.length !== 11) {
            return { ok: false, message: 'Informe um CPF com 11 dígitos e valide antes de finalizar a compra.' };
        }
        if (validateCnpj && cnpjDigits.length > 0 && cnpjDigits.length !== 14) {
            return { ok: false, message: 'Informe um CNPJ com 14 dígitos e valide antes de finalizar a compra.' };
        }

        if (cpfDigits.length === 11 && lastValidationSuccess !== true) {
            return { ok: false, message: 'Valide o CPF antes de finalizar a compra.' };
        }
        if (validateCnpj && cnpjDigits.length === 14 && lastCnpjValidationSuccess !== true) {
            return { ok: false, message: 'Valide o CNPJ antes de finalizar a compra.' };
        }

        if (required) {
            var hasValidCpf = cpfDigits.length === 11 && lastValidationSuccess === true;
            var hasValidCnpj = validateCnpj && cnpjDigits.length === 14 && lastCnpjValidationSuccess === true;
            if (!hasValidCpf && !hasValidCnpj) {
                return { ok: false, message: 'Informe e valide o CPF ou o CNPJ para finalizar a compra.' };
            }
        }

        return { ok: true };
    }

    function showBlockMessage(msg) {
        var $notice = $('.wc-cpf-validator-block-notice');
        if ($notice.length) $notice.remove();
        $notice = $('<div class="wc-cpf-validator-block-notice woocommerce-error" role="alert">' + msg + '</div>');
        var $form = $('form.checkout').first();
        if ($form.length) {
            $form.prepend($notice);
            $('html, body').animate({ scrollTop: $notice.offset().top - 80 }, 300);
        } else {
            $(document.body).prepend($notice);
        }
    }

    // Initial setup + re-setup after checkout fragments update (field may be inserted/removed by other plugins)
    bindDelegatedHandlersOnce();
    setupCpfField();
    setupCnpjField();
    setupCompanyField();
    setupPersonTypeLocking();
    $(document.body).on('updated_checkout.wcCpfValidator', function() {
        setupCpfField();
        setupCnpjField();
        setupCompanyField();
        setupPersonTypeLocking();
    });
    $(document.body).on('init_checkout.wcCpfValidator', function() {
        setupCpfField();
        setupCnpjField();
        setupCompanyField();
        setupPersonTypeLocking();
    });

    // FunnelKit can trigger its own refresh events; listen broadly.
    $(document.body).on('wfacp_updated_checkout.wcCpfValidator wfacp_step_change.wcCpfValidator wfacp_step_changed.wcCpfValidator', function() {
        setupCpfField();
        setupCnpjField();
        setupCompanyField();
        setupPersonTypeLocking();
    });

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
                        if ((wcCpfValidator && wcCpfValidator.validateCnpj) && node.querySelector('input#billing_cnpj, input[name="billing_cnpj"]')) {
                            setupCnpjField();
                            return;
                        }
                        if (node.querySelector('input#billing_company, input[name="billing_company"]')) {
                            setupCompanyField();
                            return;
                        }
                        if (node.querySelector('select#billing_persontype, select[name="billing_persontype"]')) {
                            setupPersonTypeLocking();
                            return;
                        }
                    }
                }
            }
        });
        obs.observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
});
