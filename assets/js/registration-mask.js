/**
 * Máscara dinâmica CPF/CNPJ e alternância PF/PJ no cadastro.
 */
(function ($) {
	'use strict';

	function onlyDigits(str) {
		return (str || '').replace(/\D/g, '');
	}

	function formatCpf(d) {
		d = d.slice(0, 11);
		var p1 = d.slice(0, 3);
		var p2 = d.slice(3, 6);
		var p3 = d.slice(6, 9);
		var p4 = d.slice(9, 11);
		var out = p1;
		if (p2) {
			out += '.' + p2;
		}
		if (p3) {
			out += '.' + p3;
		}
		if (p4) {
			out += '-' + p4;
		}
		return out;
	}

	function formatCnpj(d) {
		d = d.slice(0, 14);
		var p1 = d.slice(0, 2);
		var p2 = d.slice(2, 5);
		var p3 = d.slice(5, 8);
		var p4 = d.slice(8, 12);
		var p5 = d.slice(12, 14);
		var out = p1;
		if (p2) {
			out += '.' + p2;
		}
		if (p3) {
			out += '.' + p3;
		}
		if (p4) {
			out += '/' + p4;
		}
		if (p5) {
			out += '-' + p5;
		}
		return out;
	}

	function detectPersonType(digits) {
		return digits.length > 11 ? 'pessoa_juridica' : 'pessoa_fisica';
	}

	function applyPersonUi($wrap, type) {
		var $pf = $wrap.find('#wsrp_row_full_name');
		var $pj = $wrap.find('#wsrp_row_company, #wsrp_row_ie');
		if (type === 'pessoa_juridica') {
			$pf.hide().find('input').prop('required', false).removeAttr('aria-required');
			$pj.show().find('input').prop('required', true).attr('aria-required', 'true');
		} else {
			$pj.hide().find('input').prop('required', false).removeAttr('aria-required');
			$pf.show().find('#wsrp_full_name').prop('required', true).attr('aria-required', 'true');
		}
		$wrap.find('#wsrp_person_type').val(type);
	}

	function onInput($input) {
		var raw = $input.val();
		var digits = onlyDigits(raw);
		var type = detectPersonType(digits);
		var formatted = digits.length <= 11 ? formatCpf(digits) : formatCnpj(digits);
		var pos = $input[0].selectionStart;
		var oldLen = raw.length;

		$input.val(formatted);

		if (typeof pos === 'number' && document.activeElement === $input[0]) {
			var diff = formatted.length - oldLen;
			var newPos = Math.max(0, Math.min(formatted.length, pos + diff));
			$input[0].setSelectionRange(newPos, newPos);
		}

		applyPersonUi($input.closest('.wsrp-registration-fields'), type);
	}

	$(function () {
		var $doc = $('#wsrp_document');
		if (!$doc.length) {
			return;
		}
		var $wrap = $doc.closest('.wsrp-registration-fields');
		onInput($doc);
		$doc.on('input', function () {
			onInput($(this));
		});

		$('form.register').on('submit', function () {
			var digits = onlyDigits($doc.val());
			var type = detectPersonType(digits);
			$wrap.find('#wsrp_person_type').val(type);
		});
	});
})(jQuery);
