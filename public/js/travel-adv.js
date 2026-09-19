/**
 * Travel Advisory interactions
 * - Publish Advisory adds a row to Manage Alerts
 * - Save as Draft adds a Draft row
 * - Send Push updates Push Status to "Push Sent"
 * - Click "View Details" (magnifying glass) opens a centered modal card with complete advisory info
 * - Concurrency protection: Prevents double-clicks, duplicate submissions, and redundant broadcasts
 * - Synchronized with database API
 */

const STORAGE_KEY = 'seapass_travel_advisories_v2';
let isSubmittingAdvisory = false;
let lastSubmittedSignature = '';
let lastSubmittedTimestamp = 0;

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatDateTime(dtString) {
    if (!dtString) return '-';
    try {
        const d = new Date(dtString);
        if (isNaN(d.getTime())) return dtString;
        return d.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    } catch {
        return dtString;
    }
}

function severityBadge(severity) {
    const s = String(severity || '').toLowerCase();
    if (s === 'critical') return '<span class="adv-badge adv-critical">Critical</span>';
    if (s === 'warning') return '<span class="adv-badge adv-warning">Warning</span>';
    if (s === 'advisory') return '<span class="adv-badge adv-warning">Advisory</span>';
    return '<span class="adv-badge adv-info">Information</span>';
}

function statusBadge(status) {
    const s = String(status || '').toLowerCase();
    if (s === 'published') return '<span class="adv-badge adv-published">Published</span>';
    if (s === 'archived') return '<span class="adv-badge adv-draft">Archived</span>';
    return '<span class="adv-badge adv-draft">Draft</span>';
}

function pushBadge(pushStatus) {
    const s = String(pushStatus || '').toLowerCase();
    if (s === 'sent') return '<span class="adv-badge adv-sent">Push Sent</span>';
    if (s === 'pending') return '<span class="adv-badge adv-pending">Pending Push</span>';
    return '<span class="adv-badge adv-none">Not Sent</span>';
}

function loadAdvisories() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) return parsed;
        }
    } catch {
        // Fallback
    }
    return [];
}

function saveAdvisories(items) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
}

function hideEmptyRowIfNeeded() {
    const body = document.getElementById('advAlertsBody');
    const emptyRow = document.getElementById('advEmptyRow');
    if (!body || !emptyRow) return;
    const hasRealRows = Array.from(body.querySelectorAll('tr')).some(tr => tr.id !== 'advEmptyRow');
    emptyRow.style.display = hasRealRows ? 'none' : '';
}

function renderRow(item) {
    const id = item.id;
    const title = escapeHtml(item.title);
    const type = escapeHtml(item.type);
    const route = escapeHtml(item.route);

    const actions = [];
    actions.push('<button class="adv-action-btn" data-action="view" title="View details">🔍</button>');

    if (item.status === 'Published' && item.push_status === 'Pending') {
        actions.push('<button class="adv-action-btn adv-send-push" data-action="send_push" title="Send push notification">📲</button>');
    } else if (item.push_status === 'Sent') {
        actions.push('<button class="adv-action-btn" data-action="sent" title="Notification already sent" disabled style="opacity: 0.45; cursor: not-allowed;">📲</button>');
    }

    actions.push('<button class="adv-action-btn" data-action="archive" title="Archive advisory">📁</button>');

    return `
        <tr data-adv-id="${id}">
            <td><strong>${title}</strong></td>
            <td>${type}</td>
            <td>${route}</td>
            <td>${severityBadge(item.severity)}</td>
            <td>${statusBadge(item.status)}</td>
            <td>${pushBadge(item.push_status)}</td>
            <td class="adv-actions-cell">
                ${actions.join('')}
            </td>
        </tr>
    `;
}

function renderTable() {
    const body = document.getElementById('advAlertsBody');
    if (!body) return;

    const emptyRow = document.getElementById('advEmptyRow');
    let advisories = loadAdvisories();

    // Filters
    const filterSelects = document.querySelectorAll('.adv-filter');
    const severityFilter = filterSelects[0]?.value || 'All Severities';
    const statusFilter = filterSelects[1]?.value || 'All Status';

    if (severityFilter !== 'All Severities') {
        advisories = advisories.filter(a => String(a.severity).toLowerCase() === severityFilter.toLowerCase());
    }

    if (statusFilter !== 'All Status') {
        advisories = advisories.filter(a => String(a.status).toLowerCase() === statusFilter.toLowerCase());
    }

    body.innerHTML = '';
    if (emptyRow) body.appendChild(emptyRow);

    advisories.forEach(item => {
        body.insertAdjacentHTML('beforeend', renderRow(item));
    });

    hideEmptyRowIfNeeded();
}

function getFormValues() {
    const title = document.getElementById('adv_title')?.value?.trim() || '';
    const type = document.getElementById('adv_type')?.value || '';
    const route = document.getElementById('adv_route')?.value || '';
    const effectiveFrom = document.getElementById('adv_effective_from')?.value || '';
    const until = document.getElementById('adv_until')?.value || '';
    const severity = document.getElementById('adv_severity')?.value || 'Information';
    const message = document.getElementById('adv_message')?.value?.trim() || '';
    const publishPush = Boolean(document.getElementById('adv_publish_push')?.checked);

    return { title, type, route, effectiveFrom, until, severity, message, publishPush };
}

function addAdvisory(status) {
    if (isSubmittingAdvisory) {
        console.warn('[TravelAdv] Submission already in flight. Ignoring duplicate action.');
        return;
    }

    const { title, type, route, effectiveFrom, until, severity, message, publishPush } = getFormValues();
    if (!title || !type || !route || !severity || !message) {
        alert('Please fill in Title, Type, Route, Severity, and Advisory Message.');
        return;
    }

    // Client-side debounce check (prevent rapid identical double clicks within 20s)
    const currentSignature = `${title}|${route}`.toLowerCase();
    const now = Date.now();
    if (currentSignature === lastSubmittedSignature && (now - lastSubmittedTimestamp < 20000)) {
        alert('This advisory was just published! Duplicate submission prevented.');
        return;
    }

    isSubmittingAdvisory = true;
    const submitBtn = document.querySelector('#advisoryForm button[type="submit"]');
    const draftBtn = document.getElementById('adv_save_draft');

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = status === 'Published' ? 'Publishing...' : 'Saving...';
    }
    if (draftBtn) draftBtn.disabled = true;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch('/admin/travel-advisory', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
            title,
            type,
            route,
            effective_from: effectiveFrom,
            until,
            severity,
            status,
            message,
            publish_push: publishPush
        })
    })
    .then(res => res.json())
    .then(data => {
        lastSubmittedSignature = currentSignature;
        lastSubmittedTimestamp = Date.now();

        const id = data.advisory ? data.advisory.id : `${Date.now()}`;
        // If publishPush was checked upon publish, push is already sent on backend
        const pushStatus = (status === 'Published' && publishPush) ? 'Sent' : (data.push_status || 'Not Sent');

        const advisories = loadAdvisories();
        // Check if an entry with this title already exists to avoid duplicate rows
        const existingIdx = advisories.findIndex(a => String(a.title).trim().toLowerCase() === title.toLowerCase());
        const record = {
            id,
            title,
            type,
            route,
            effectiveFrom,
            until,
            severity,
            status,
            push_status: pushStatus,
            message,
            created_at: new Date().toISOString(),
        };

        if (existingIdx >= 0) {
            advisories[existingIdx] = record;
        } else {
            advisories.unshift(record);
        }

        saveAdvisories(advisories);
        renderTable();
        document.getElementById('advisoryForm')?.reset();
        const publishPushCheckbox = document.getElementById('adv_publish_push');
        if (publishPushCheckbox) publishPushCheckbox.checked = true;

        if (data.is_duplicate) {
            alert(data.message || 'Advisory is already published.');
        }
    })
    .catch(err => {
        console.error('Error submitting advisory:', err);
        alert('Could not publish advisory. Please try again.');
    })
    .finally(() => {
        setTimeout(() => {
            isSubmittingAdvisory = false;
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Publish Advisory';
            }
            if (draftBtn) draftBtn.disabled = false;
        }, 1500);
    });
}

function updateAdvisory(id, patch) {
    const advisories = loadAdvisories();
    const idx = advisories.findIndex(a => String(a.id) === String(id));
    if (idx === -1) return;
    advisories[idx] = { ...advisories[idx], ...patch };
    saveAdvisories(advisories);
    renderTable();
}

function showAdvisoryModal(item) {
    const modal = document.getElementById('advModalOverlay');
    if (!modal) return;

    const titleEl = document.getElementById('advModalTitle');
    const typeEl = document.getElementById('advModalType');
    const routeEl = document.getElementById('advModalRoute');
    const effectiveFromEl = document.getElementById('advModalEffectiveFrom');
    const untilEl = document.getElementById('advModalUntil');
    const messageEl = document.getElementById('advModalMessage');
    const badgesEl = document.getElementById('advModalBadges');

    if (titleEl) titleEl.textContent = item.title || 'Advisory Details';
    if (typeEl) typeEl.textContent = item.type || '-';
    if (routeEl) routeEl.textContent = item.route || '-';
    
    if (effectiveFromEl) {
        effectiveFromEl.textContent = item.effectiveFrom ? formatDateTime(item.effectiveFrom) : (item.created_at ? formatDateTime(item.created_at) : 'Immediate');
    }
    if (untilEl) {
        untilEl.textContent = item.until ? formatDateTime(item.until) : 'Until Further Notice';
    }
    if (messageEl) {
        messageEl.textContent = item.message || 'No additional message text provided.';
    }

    if (badgesEl) {
        badgesEl.innerHTML = `
            ${severityBadge(item.severity)}
            ${statusBadge(item.status)}
            ${pushBadge(item.push_status)}
        `;
    }

    modal.classList.add('active');
}

function closeAdvisoryModal() {
    const modal = document.getElementById('advModalOverlay');
    if (modal) {
        modal.classList.remove('active');
    }
}

function syncWithDatabase() {
    fetch('/api/advisories')
        .then(res => res.json())
        .then(res => {
            if (res.success && Array.isArray(res.data) && res.data.length > 0) {
                const dbItems = res.data.map(item => ({
                    id: item.id,
                    title: item.title,
                    type: item.type || 'General Advisory',
                    route: item.route || 'All Routes',
                    effectiveFrom: item.effective_from || '',
                    until: item.until || '',
                    severity: item.severity || 'Information',
                    status: 'Published',
                    push_status: 'Sent',
                    message: item.content ? item.content.replace(/<[^>]*>?/gm, '').trim() : '',
                    created_at: item.published_at || item.created_at || new Date().toISOString(),
                }));
                saveAdvisories(dbItems);
                renderTable();
            }
        })
        .catch(e => console.log('Advisory background sync note:', e));
}

document.addEventListener('DOMContentLoaded', () => {
    renderTable();
    syncWithDatabase();

    // Filter listeners
    document.querySelectorAll('.adv-filter').forEach(select => {
        select.addEventListener('change', renderTable);
    });

    // Form submission
    const form = document.getElementById('advisoryForm');
    form?.addEventListener('submit', (e) => {
        e.preventDefault();
        addAdvisory('Published');
    });

    // Save draft
    document.getElementById('adv_save_draft')?.addEventListener('click', () => {
        addAdvisory('Draft');
    });

    // Modal Close Buttons
    document.getElementById('advModalCloseBtn')?.addEventListener('click', closeAdvisoryModal);
    document.getElementById('advModalCloseFooterBtn')?.addEventListener('click', closeAdvisoryModal);
    
    // Close modal on click backdrop
    document.getElementById('advModalOverlay')?.addEventListener('click', (e) => {
        if (e.target.id === 'advModalOverlay') {
            closeAdvisoryModal();
        }
    });

    // Close modal on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAdvisoryModal();
        }
    });

    // Row actions (event delegation)
    document.getElementById('advAlertsBody')?.addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;

        const row = btn.closest('tr[data-adv-id]');
        const id = row?.getAttribute('data-adv-id');
        if (!id) return;

        const action = btn.getAttribute('data-action');
        if (action === 'send_push') {
            if (btn.disabled || btn.classList.contains('is-busy')) return;

            const advisories = loadAdvisories();
            const item = advisories.find(a => String(a.id) === String(id));

            if (item && item.push_status === 'Sent') {
                alert('This advisory notification has already been broadcasted.');
                btn.disabled = true;
                return;
            }

            btn.disabled = true;
            btn.classList.add('is-busy');
            const originalIcon = btn.innerHTML;
            btn.innerHTML = '⏳';

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            fetch('/admin/travel-advisory/send-push', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(item || { id })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateAdvisory(id, { push_status: 'Sent' });
                    alert(data.message || 'Advisory successfully broadcasted to registered users!');
                } else {
                    alert('Advisory notification notice: ' + (data.message || 'Already processed'));
                    btn.disabled = false;
                    btn.innerHTML = originalIcon;
                }
            })
            .catch(() => {
                updateAdvisory(id, { push_status: 'Sent' });
            })
            .finally(() => {
                btn.classList.remove('is-busy');
            });
            return;
        }

        if (action === 'archive') {
            updateAdvisory(id, { status: 'Archived' });
            return;
        }

        if (action === 'view') {
            const advisories = loadAdvisories();
            const item = advisories.find(a => String(a.id) === String(id));
            if (item) {
                showAdvisoryModal(item);
            }
        }
    });
});
