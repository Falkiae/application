// Notification polling
let notifPollInterval = null;

function initNotifications() {
    loadNotifications();
    notifPollInterval = setInterval(loadNotifications, 30000);

    document.getElementById('notifBtn')?.addEventListener('click', () => {
        document.getElementById('notifPanel').classList.add('open');
        document.getElementById('notifOverlay').classList.add('show');
        loadNotifications(true);
    });

    document.getElementById('notifClose')?.addEventListener('click', closeNotifPanel);
    document.getElementById('notifOverlay')?.addEventListener('click', closeNotifPanel);

    document.getElementById('markAllRead')?.addEventListener('click', async () => {
        const fd = new FormData();
        fd.append('ids', 'all');
        await fetch('api/notifications_read.php', {method:'POST', body:fd});
        loadNotifications();
    });
}

function closeNotifPanel() {
    document.getElementById('notifPanel').classList.remove('open');
    document.getElementById('notifOverlay').classList.remove('show');
}

async function loadNotifications(markVisible = false) {
    try {
        const res = await fetch('api/notifications.php');
        const data = await res.json();

        // Update badge
        const badge = document.querySelector('.notif-badge');
        const btn = document.getElementById('notifBtn');
        if (data.unread > 0) {
            if (!badge) {
                const b = document.createElement('span');
                b.className = 'notif-badge';
                b.textContent = data.unread > 9 ? '9+' : data.unread;
                btn?.appendChild(b);
            } else {
                badge.textContent = data.unread > 9 ? '9+' : data.unread;
            }
        } else if (badge) {
            badge.remove();
        }

        // Render list
        const list = document.getElementById('notifList');
        if (!list) return;

        if (!data.items || data.items.length === 0) {
            list.innerHTML = '<div class="notif-empty">Aucune notification</div>';
            return;
        }

        const actionIcons = {
            create: '✚',
            update: '✎',
            delete: '✕',
        };
        const actionColors = {
            create: '#22c55e',
            update: '#596FF3',
            delete: '#bd264b',
        };

        list.innerHTML = data.items.map(n => `
            <div class="notif-item ${n.is_read ? '' : 'notif-unread'}" ${n.service_id ? `onclick="window.location='index.php?page=prestation_edit&id=${n.service_id}'"` : ''}>
                <div class="notif-avatar" style="background:${n.from_color}">${n.from_name.charAt(0).toUpperCase()}</div>
                <div class="notif-content">
                    <div class="notif-message">${escHtml(n.message)}</div>
                    <div class="notif-time">${n.time_ago}</div>
                </div>
                <div class="notif-dot" style="background:${actionColors[n.action] || '#596FF3'}">${actionIcons[n.action] || ''}</div>
            </div>
        `).join('');

        // Mark visible notifications as read if panel is open
        if (markVisible || document.getElementById('notifPanel').classList.contains('open')) {
            const unreadIds = data.items.filter(n => !n.is_read).map(n => n.id).join(',');
            if (unreadIds) {
                const fd = new FormData();
                fd.append('ids', unreadIds);
                fetch('api/notifications_read.php', {method:'POST', body:fd});
            }
        }
    } catch (e) {
        // Silently fail for notification errors
    }
}

function escHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// Image compression utility (used across pages)
async function compressImage(file, maxWidth = 1200, quality = 0.82) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onerror = () => reject(new Error('Impossible de lire le fichier.'));
        reader.onload = (e) => {
            const img = new Image();
            img.onerror = () => reject(new Error('Format non supporté. Choisissez une photo JPEG ou PNG depuis votre galerie.'));
            img.onload = () => {
                const canvas = document.createElement('canvas');
                let w = img.width, h = img.height;
                if (w > maxWidth) {
                    h = Math.round(h * maxWidth / w);
                    w = maxWidth;
                }
                canvas.width = w;
                canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                canvas.toBlob((blob) => {
                    if (blob) resolve(blob);
                    else reject(new Error('Compression échouée, réessayez.'));
                }, 'image/jpeg', quality);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}

// Init on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    initNotifications();
});
