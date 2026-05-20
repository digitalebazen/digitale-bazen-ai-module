(function () {
	'use strict';

	const config = window.dbAi || {};
	let rows = [];
	let currentSecondary = [];

	// Doelschema voor de Excel/CSV import wizard. Volgorde bepaalt CSV header-volgorde.
	const TARGET_FIELDS = [
		{ key: 'Zoekwoord',          required: true,  synonyms: ['zoekwoord','keyword','search term','search query','query','term','keywords'] },
		{ key: 'Maandelijks volume', required: false, synonyms: ['maandelijks volume','volume','monthly searches','search volume','monthly volume','msv','searches','search vol'] },
		{ key: 'Pagina',             required: false, synonyms: ['pagina','page','cluster','category','categorie','campagne','campaign','campagnenaam'] },
		{ key: 'Onderwerp',          required: false, synonyms: ['onderwerp','topic','subject','intent','theme','thema','advertentiegroep','ad group','adgroup','ad-group'] },
		{ key: 'Concurrentie',       required: false, synonyms: ['concurrentie','competition','difficulty','kd','comp','keyword difficulty'] },
		{ key: 'CPC Laag',           required: false, synonyms: ['cpc laag','cpc low','min cpc','low bid','cpc min','lower cpc','low cpc'] },
		{ key: 'CPC hoog',           required: false, synonyms: ['cpc hoog','cpc high','max cpc','high bid','cpc max','higher cpc','high cpc'] },
	];

	let wizardState = null; // {headers: [], dataRows: [], mapping: {}, file: File}

	function $(selector) {
		return document.querySelector(selector);
	}

	function renderQuota(remaining) {
		const el = $('#db-ai-quota');
		if (!el) return;
		const limit = config.rateLimit || 0;
		const used  = Math.max(0, limit - (typeof remaining === 'number' ? remaining : config.rateRemaining || 0));
		el.textContent = (config.i18n.quotaLabel || '%1$d van %2$d generaties vandaag gebruikt')
			.replace('%1$d', used)
			.replace('%2$d', limit);
	}

	function setStatus(selectorOrEl, message, type) {
		const el = typeof selectorOrEl === 'string' ? $(selectorOrEl) : selectorOrEl;
		if (!el) return;
		el.textContent = message || '';
		el.className = 'db-ai-status' + (type ? ' is-' + type : '');
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function formatOptionLabel(row) {
		const parts = [row.zoekwoord];
		if (row.volume && row.volume > 0) parts.push('(' + row.volume + ')');
		if (row.onderwerp) parts.push('— ' + row.onderwerp);
		return parts.join(' ');
	}

	function buildSelect(grouped) {
		const select = $('#db-ai-keyword-select');
		if (!select) return;
		select.innerHTML = '';

		const placeholder = document.createElement('option');
		placeholder.value = '';
		placeholder.textContent = config.i18n.choosePlaceholder || '— Kies een hoofdzoekwoord —';
		select.appendChild(placeholder);

		Object.keys(grouped).sort().forEach(function (page) {
			const group = document.createElement('optgroup');
			group.label = page;
			const topics = grouped[page];
			Object.keys(topics).sort().forEach(function (topic) {
				topics[topic].forEach(function (row) {
					const opt = document.createElement('option');
					opt.value = row.zoekwoord;
					opt.textContent = formatOptionLabel(row);
					group.appendChild(opt);
				});
			});
			select.appendChild(group);
		});

		select.disabled = false;
		select.value = '';
	}

	function renderSecondaryPreview(mainKeyword) {
		const previewEl = $('#db-ai-secondary-preview');
		const generateBtn = $('#db-ai-generate-btn');

		if (!mainKeyword) {
			currentSecondary = [];
			if (previewEl) previewEl.innerHTML = '';
			if (generateBtn) generateBtn.disabled = true;
			return;
		}

		const mainRow = rows.find(function (r) {
			return r.zoekwoord.toLowerCase() === mainKeyword.toLowerCase();
		});
		const topic = mainRow ? mainRow.onderwerp : '';

		currentSecondary = rows
			.filter(function (r) {
				return topic && r.onderwerp === topic && r.zoekwoord.toLowerCase() !== mainKeyword.toLowerCase();
			})
			.map(function (r) { return r.zoekwoord; });

		if (previewEl) {
			let html = '<h3>' + escapeHtml(config.i18n.previewTitle || 'Geselecteerd') + '</h3>';
			html += '<p><strong>' + escapeHtml(config.i18n.mainLabel || 'Hoofdzoekwoord:') + '</strong> ' + escapeHtml(mainKeyword) + '</p>';
			if (topic) {
				html += '<p><strong>' + escapeHtml(config.i18n.topicLabel || 'Onderwerp:') + '</strong> ' + escapeHtml(topic) + '</p>';
			}
			if (currentSecondary.length) {
				html += '<p><strong>' + escapeHtml(config.i18n.secondaryLabel || 'Secundaire keywords:') + '</strong></p>';
				html += '<ul>';
				currentSecondary.forEach(function (kw) {
					html += '<li>' + escapeHtml(kw) + '</li>';
				});
				html += '</ul>';
			} else {
				html += '<p><em>' + escapeHtml(config.i18n.noSecondary || 'Geen secundaire keywords (geen ander zoekwoord met hetzelfde onderwerp).') + '</em></p>';
			}
			previewEl.innerHTML = html;
		}

		if (generateBtn) generateBtn.disabled = false;
	}

	// ─── Excel/CSV import wizard ────────────────────────────────────────────

	function handleFile(file) {
		if (!file) return;
		setStatus('#db-ai-status', config.i18n.parsing || 'Bestand lezen…', 'loading');
		hideMapping();

		file.arrayBuffer()
			.then(function (buf) {
				const wb = window.XLSX.read(buf, { type: 'array', cellDates: false, raw: false });
				if (!wb.SheetNames.length) {
					throw new Error('No sheets');
				}
				const sheet = wb.Sheets[wb.SheetNames[0]];
				const aoa = window.XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '', blankrows: false });
				if (!aoa || !aoa.length) {
					throw new Error('Empty sheet');
				}

				const headerIdx = detectHeaderRow(aoa);
				const headers   = (aoa[headerIdx] || []).map(function (h) { return String(h || '').trim(); });
				const dataRows  = aoa.slice(headerIdx + 1).filter(function (r) {
					return r.some(function (c) { return String(c || '').trim() !== ''; });
				});

				if (!headers.length) {
					throw new Error('No header row detected');
				}

				const mapping = autoMap(headers);

				wizardState = { headers: headers, dataRows: dataRows, mapping: mapping, file: file };

				// Altijd mapping-panel tonen: gebruiker ziet preview + kan brackets / totaal-rijen / etc. herkennen
				// vóór doorgaan. Auto-suggesties zijn al ingevuld dus 1-klik bevestigen is genoeg.
				renderMapping();
			})
			.catch(function (err) {
				setStatus(
					'#db-ai-status',
					(config.i18n.parseFailed || 'Bestand kon niet gelezen worden.') + ' (' + (err && err.message ? err.message : 'error') + ')',
					'error'
				);
			});
	}

	function detectHeaderRow(aoa) {
		// Scan eerste 10 rijen voor een rij die er als headers uitziet
		const zoekwoordSyns = TARGET_FIELDS[0].synonyms;
		for (let i = 0; i < Math.min(aoa.length, 10); i++) {
			const cells = (aoa[i] || []).map(function (c) { return String(c || '').trim().toLowerCase(); });
			const nonEmpty = cells.filter(function (c) { return c !== ''; }).length;
			const hasKeywordMatch = cells.some(function (c) {
				return zoekwoordSyns.some(function (s) { return c === s || c.indexOf(s) !== -1; });
			});
			if (nonEmpty >= 2 && hasKeywordMatch) return i;
		}
		// Fallback: eerste niet-lege rij
		for (let i = 0; i < aoa.length; i++) {
			if ((aoa[i] || []).some(function (c) { return String(c || '').trim() !== ''; })) return i;
		}
		return 0;
	}

	function autoMap(headers) {
		const normalized = headers.map(function (h) { return String(h).trim().toLowerCase(); });
		const mapping = {};
		const usedIdx = new Set();
		TARGET_FIELDS.forEach(function (field) {
			const targetLower = field.key.toLowerCase();
			let idx = normalized.findIndex(function (h, i) {
				return !usedIdx.has(i) && h && h === targetLower;
			});
			if (idx === -1) {
				idx = normalized.findIndex(function (h, i) {
					if (usedIdx.has(i) || !h) return false;
					return field.synonyms.some(function (s) { return h === s || h.indexOf(s) !== -1; });
				});
			}
			mapping[field.key] = idx;
			if (idx >= 0) usedIdx.add(idx);
		});
		return mapping;
	}

	function hideMapping() {
		const panel = $('#db-ai-mapping');
		if (panel) panel.hidden = true;
	}

	function renderMapping() {
		const panel = $('#db-ai-mapping');
		const tbody = $('#db-ai-mapping-rows');
		const previewEl = $('#db-ai-mapping-preview-table');
		if (!panel || !tbody || !wizardState) return;

		const headers = wizardState.headers;
		const mapping = wizardState.mapping;

		tbody.innerHTML = '';
		TARGET_FIELDS.forEach(function (field) {
			const tr = document.createElement('tr');
			const tdLabel = document.createElement('td');
			tdLabel.textContent = field.key + (field.required ? ' *' : '');
			tr.appendChild(tdLabel);

			const tdSelect = document.createElement('td');
			const select = document.createElement('select');
			select.dataset.field = field.key;

			const noneOpt = document.createElement('option');
			noneOpt.value = '-1';
			noneOpt.textContent = config.i18n.mappingNone || '(geen)';
			select.appendChild(noneOpt);

			headers.forEach(function (h, i) {
				const opt = document.createElement('option');
				opt.value = String(i);
				opt.textContent = h !== '' ? h : '(kolom ' + (i + 1) + ')';
				if (mapping[field.key] === i) opt.selected = true;
				select.appendChild(opt);
			});

			tdSelect.appendChild(select);
			tr.appendChild(tdSelect);
			tbody.appendChild(tr);
		});

		// Preview eerste 5 rijen
		if (previewEl) {
			let html = '<table class="db-ai-preview-table"><thead><tr>';
			headers.forEach(function (h) {
				html += '<th>' + escapeHtml(h || '—') + '</th>';
			});
			html += '</tr></thead><tbody>';
			wizardState.dataRows.slice(0, 5).forEach(function (row) {
				html += '<tr>';
				headers.forEach(function (_, i) {
					html += '<td>' + escapeHtml(row[i] != null ? row[i] : '') + '</td>';
				});
				html += '</tr>';
			});
			html += '</tbody></table>';
			previewEl.innerHTML = html;
		}

		panel.hidden = false;
		setStatus(
			'#db-ai-status',
			(config.i18n.mappingReady || 'Headers gevonden — controleer de mapping hieronder.'),
			''
		);
	}

	function collectMappingFromUI() {
		const selects = document.querySelectorAll('#db-ai-mapping-rows select');
		const mapping = {};
		selects.forEach(function (s) {
			mapping[s.dataset.field] = parseInt(s.value, 10);
		});
		return mapping;
	}

	function csvEscape(value) {
		if (value === null || value === undefined) return '';
		const s = String(value);
		if (/[",\n\r]/.test(s)) {
			return '"' + s.replace(/"/g, '""') + '"';
		}
		return s;
	}

	function isSummaryRow(row) {
		// Google Ads / vergelijkbare exports zetten samenvattingen onderaan met "Totaal:" / "Total:" prefix.
		return row.some(function (cell) {
			const s = String(cell == null ? '' : cell).trim();
			return /^(totaal|total)\s*:/i.test(s);
		});
	}

	function stripKeywordMarkers(value) {
		// Google Ads wrap-markers strippen: [exact], "phrase", 'soms', +modified
		return String(value || '')
			.trim()
			.replace(/^[\[\"'+]+/, '')
			.replace(/[\]\"']+$/, '')
			.trim();
	}

	function buildCleanCsv(mapping) {
		const lines = [];
		lines.push(TARGET_FIELDS.map(function (f) { return csvEscape(f.key); }).join(','));
		wizardState.dataRows.forEach(function (row) {
			if (isSummaryRow(row)) return;

			const values = TARGET_FIELDS.map(function (f, fieldIdx) {
				const idx = mapping[f.key];
				if (idx == null || idx < 0 || idx >= row.length) return '';
				let value = String(row[idx] == null ? '' : row[idx]).trim();
				if (fieldIdx === 0) {
					// Zoekwoord: strip Google Ads match-type wrappers
					value = stripKeywordMarkers(value);
				}
				return csvEscape(value);
			});
			// Skip rijen zonder zoekwoord
			if (values[0] === '' || values[0] === '""') return;
			lines.push(values.join(','));
		});
		return lines.join('\n');
	}

	function submitMappedCsv() {
		if (!wizardState) return;
		const mapping = collectMappingFromUI();
		// Mapping kan ook auto-geinjecteerd zijn (skipped UI) — fallback:
		const finalMapping = Object.keys(mapping).length ? mapping : wizardState.mapping;

		if (finalMapping['Zoekwoord'] == null || finalMapping['Zoekwoord'] < 0) {
			setStatus('#db-ai-status', config.i18n.mappingMissingKeyword || 'Selecteer een bron-kolom voor "Zoekwoord".', 'error');
			return;
		}

		const csv  = buildCleanCsv(finalMapping);
		const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
		const fileName = (wizardState.file && wizardState.file.name ? wizardState.file.name : 'upload') + '.csv';
		const csvFile = new File([blob], fileName.replace(/\.(xlsx|xls|ods|csv)$/i, '') + '.csv', { type: 'text/csv' });

		hideMapping();
		setStatus('#db-ai-status', config.i18n.uploading || 'Bezig met uploaden…', 'loading');

		const formData = new FormData();
		formData.append('action', 'db_ai_parse_csv');
		formData.append('nonce', config.nonce);
		formData.append('csv', csvFile);

		fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					const msg = (json && json.data && json.data.message) || (config.i18n.uploadFailed || 'Upload mislukt.');
					setStatus('#db-ai-status', msg, 'error');
					return;
				}
				rows = json.data.rows || [];
				buildSelect(json.data.grouped || {});
				setStatus(
					'#db-ai-status',
					(config.i18n.uploadOk || '%d zoekwoorden geladen.').replace('%d', json.data.count),
					'success'
				);
				renderSecondaryPreview('');
			})
			.catch(function () {
				setStatus('#db-ai-status', config.i18n.networkError || 'Netwerkfout.', 'error');
			});
	}

	function downloadTemplateCsv(e) {
		if (e) e.preventDefault();
		const lines = [];
		lines.push(TARGET_FIELDS.map(function (f) { return csvEscape(f.key); }).join(','));
		// Voorbeeld-rij
		lines.push('Online Marketing Bureau,2900,Online Marketing,Bedrijf,Normaal,"6,20","12,40"');
		const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
		const url  = URL.createObjectURL(blob);
		const a    = document.createElement('a');
		a.href = url;
		a.download = 'db-ai-zoekwoorden-template.csv';
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		setTimeout(function () { URL.revokeObjectURL(url); }, 100);
	}

	function getCurrentKeyword() {
		const select = $('#db-ai-keyword-select');
		return select ? select.value : '';
	}

	function generateBlog() {
		const keyword = getCurrentKeyword();
		if (!keyword) return;

		const btn      = $('#db-ai-generate-btn');
		const resultEl = $('#db-ai-generate-result');

		if (resultEl) resultEl.innerHTML = '';
		if (btn) btn.disabled = true;
		setStatus('#db-ai-generate-status', config.i18n.generateRunning || 'AI + afbeeldingen ophalen… kan 30-60 sec duren.', 'loading');

		const formData = new FormData();
		formData.append('action', 'db_ai_generate');
		formData.append('nonce', config.nonce);
		formData.append('main_keyword', keyword);
		currentSecondary.forEach(function (kw) {
			formData.append('secondary_keywords[]', kw);
		});

		fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (btn) btn.disabled = false;
				if (!json || !json.success) {
					const data = (json && json.data) || {};
					const msg = data.message || (config.i18n.generateFailed || 'Generatie mislukt.');
					setStatus('#db-ai-generate-status', msg, 'error');
					if (resultEl && Array.isArray(data.validation_errors) && data.validation_errors.length) {
						let html = '<div class="db-ai-errors"><h3>' + escapeHtml(config.i18n.errorsLabel || 'Validatiefouten') + '</h3><ul>';
						data.validation_errors.forEach(function (e) { html += '<li>' + escapeHtml(e) + '</li>'; });
						html += '</ul></div>';
						resultEl.innerHTML = html;
					}
					return;
				}
				renderGenerateResult(json.data);
			})
			.catch(function () {
				if (btn) btn.disabled = false;
				setStatus('#db-ai-generate-status', config.i18n.networkError || 'Netwerkfout.', 'error');
			});
	}

	function renderGenerateResult(data) {
		const tokensMsg = (config.i18n.tokensLabel || 'Tokens: %d').replace('%d', data.tokens || 0) + ' · ' + (data.model || '');
		const remaining = (typeof data.remaining_today === 'number')
			? ' · ' + (config.i18n.remainingLabel || 'Nog %d generaties vandaag.').replace('%d', data.remaining_today)
			: '';

		setStatus(
			'#db-ai-generate-status',
			(config.i18n.generateOk || 'Draft aangemaakt') + ' · ' + tokensMsg + remaining,
			'success'
		);

		renderQuota(data.remaining_today);

		const el = $('#db-ai-generate-result');
		if (!el) return;

		let html = '<div class="db-ai-generate-card">';
		html += '<p><strong>' + escapeHtml(config.i18n.draftLabel || 'Post ID:') + '</strong> ' + escapeHtml(data.post_id) + '</p>';
		if (data.edit_link) {
			html += '<p><a class="button button-primary" href="' + escapeHtml(data.edit_link) + '" target="_blank" rel="noopener">'
				+ escapeHtml(config.i18n.openDraftLabel || 'Open draft in nieuwe tab') + '</a> ';
		}
		if (data.preview_link) {
			html += '<a class="button" href="' + escapeHtml(data.preview_link) + '" target="_blank" rel="noopener">'
				+ escapeHtml(config.i18n.previewDraftLabel || 'Preview') + '</a></p>';
		} else {
			html += '</p>';
		}

		if (Array.isArray(data.warnings) && data.warnings.length) {
			html += '<div class="db-ai-warnings"><h3>' + escapeHtml(config.i18n.warningsLabel || 'Waarschuwingen') + '</h3><ul>';
			data.warnings.forEach(function (w) { html += '<li>' + escapeHtml(w) + '</li>'; });
			html += '</ul></div>';
		}

		html += '</div>';
		el.innerHTML = html;
	}

	document.addEventListener('DOMContentLoaded', function () {
		const fileInput = $('#db-ai-csv-file');
		const select    = $('#db-ai-keyword-select');

		if (fileInput) {
			fileInput.addEventListener('change', function (e) {
				const file = e.target.files && e.target.files[0];
				if (!file) return;
				handleFile(file);
			});
		}

		if (select) {
			select.addEventListener('change', function (e) {
				renderSecondaryPreview(e.target.value);
			});
		}

		const applyBtn  = $('#db-ai-mapping-apply');
		const cancelBtn = $('#db-ai-mapping-cancel');
		if (applyBtn)  applyBtn.addEventListener('click', submitMappedCsv);
		if (cancelBtn) cancelBtn.addEventListener('click', function () {
			wizardState = null;
			hideMapping();
			setStatus('#db-ai-status', '', '');
			if (fileInput) fileInput.value = '';
		});

		const templateLink = $('#db-ai-template-download');
		if (templateLink) templateLink.addEventListener('click', downloadTemplateCsv);

		const generateBtn = $('#db-ai-generate-btn');
		if (generateBtn) {
			generateBtn.addEventListener('click', generateBlog);
		}

		renderQuota();
	});
})();
