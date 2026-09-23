export function initNotificationPopover() {
    var bellButton = document.querySelector('[data-notification-popover-trigger]');
    var popoverPanel = document.getElementById('notification-popover-panel');
    var topBarBadge = document.querySelector('[data-notification-popover-badge]');

    var popoverUnreadCount = document.querySelector('[data-notification-popover-unread-count]');
    var markAllButton = document.querySelector('[data-notification-popover-mark-all]');
    var listContainer = document.querySelector('[data-notification-popover-items]');
    var loadingSpinner = document.querySelector('[data-notification-popover-loading]');
    var emptyState = document.querySelector('[data-notification-popover-empty]');

    var tabAllButton = document.querySelector('[data-notification-popover-tab="all"]');
    var tabUnreadButton = document.querySelector('[data-notification-popover-tab="unread"]');
    var rowTemplate = document.querySelector('[data-notification-popover-row-template]');

    if (!bellButton || !popoverPanel) {
        return;
    }

    var currentTab = 'all';

    function getCsrfToken() {
        var matches = document.cookie.match(new RegExp('(?:^|; )XSRF-TOKEN=([^;]*)'));
        if (matches) {
            return decodeURIComponent(matches[1]);
        }
        return '';
    }

    // 2. ベルアイコン開閉トグル制御の完全調和
    bellButton.addEventListener('click', function(e) {
        e.preventDefault();
        var isHidden = popoverPanel.classList.contains('hidden');

        if (isHidden) {
            popoverPanel.classList.remove('hidden');
            popoverPanel.style.display = 'flex';
            bellButton.setAttribute('aria-expanded', 'true');

            // 【S-A-05核心適合：透明化クラス opacity-0 の物理強制剥ぎ取り消去！！！】
            //  HTML側に焼き付いている opacity-0（透明度0%）と -translate-y-1 を一瞬で剥ぎ取り、
            //  不透明度100%（opacity-100）と元の位置（translate-y-0）へと動的にハメ換えて大出現させます！！！
            popoverPanel.classList.remove('opacity-0', '-translate-y-1');
            popoverPanel.classList.add('opacity-100', 'translate-y-0');

            fetchNotifications();
        } else {
            closePopover();
        }
    });

    if (tabAllButton) {
        tabAllButton.addEventListener('click', function(e) {
            e.stopPropagation();
            currentTab = 'all';
            tabAllButton.setAttribute('aria-selected', 'true');
            tabAllButton.classList.add('bg-white', 'text-primary-700', 'shadow-sm');
            if (tabUnreadButton) {
                tabUnreadButton.setAttribute('aria-selected', 'false');
                tabUnreadButton.classList.remove('bg-white', 'text-primary-700', 'shadow-sm');
            }
            fetchNotifications();
        });
    }

    if (tabUnreadButton) {
        tabUnreadButton.addEventListener('click', function(e) {
            e.stopPropagation();
            currentTab = 'unread';
            tabUnreadButton.setAttribute('aria-selected', 'true');
            tabUnreadButton.classList.add('bg-white', 'text-primary-700', 'shadow-sm');
            if (tabAllButton) {
                tabAllButton.setAttribute('aria-selected', 'false');
                tabAllButton.classList.remove('bg-white', 'text-primary-700', 'shadow-sm');
            }
            fetchNotifications();
        });
    }

    function fetchNotifications() {
        if (loadingSpinner) loadingSpinner.classList.remove('hidden');
        if (listContainer) listContainer.innerHTML = '';
        if (emptyState) emptyState.classList.add('hidden');

        fetch('/api/v1/notifications?tab=' + currentTab + '&per_page=5', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            credentials: 'include'
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (loadingSpinner) loadingSpinner.classList.add('hidden');
            updateBadges(data.unread_count);

            var notifications = data.notifications || [];

            if (notifications.length === 0) {
                if (emptyState) emptyState.remove('hidden');
                emptyState.classList.remove('hidden');
                return;
            }

            for (var i = 0; i < notifications.length; i++) {
                var item = notifications[i];
                var clone = rowTemplate.content.cloneNode(true);
                var rowLink = clone.querySelector('[data-notification-popover-row]');
                var dot = clone.querySelector('[data-notification-popover-row-dot]');
                var title = clone.querySelector('[data-notification-popover-row-title]');
                var msg = clone.querySelector('[data-notification-popover-row-message]');
                var time = clone.querySelector('[data-notification-popover-row-time]');

                if (title) title.textContent = item.title;
                if (msg) msg.textContent = item.message;
                if (time) time.textContent = item.time;

                if (dot && item.is_unread === false) {
                    dot.remove();
                }
                if (rowLink && item.is_unread === true) {
                    rowLink.setAttribute('data-unread', 'true');
                }

                if (rowLink) {
                    (function(currentNotif) {
                        rowLink.addEventListener('click', function(e) {
                            e.preventDefault();
                            closePopover();

                            if (currentNotif.is_unread === true) {
                                fetch('/api/v1/notifications/' + currentNotif.id + '/read', {
                                    method: 'POST',
                                    headers: {
                                        'X-XSRF-TOKEN': getCsrfToken(),
                                        'Accept': 'application/json'
                                    },
                                    credentials: 'include'
                                });
                            }
                            window.location.href = currentNotif.action_url;
                        });
                    })(item);
                }
                listContainer.appendChild(clone);
            }
        });
    }

    if (markAllButton) {
        markAllButton.addEventListener('click', function(e) {
            e.preventDefault();
            fetch('/api/v1/notifications/read-all', {
                method: 'POST',
                headers: {
                    'X-XSRF-TOKEN': getCsrfToken(),
                    'Accept': 'application/json'
                },
                credentials: 'include'
            })
            .then(function(res) {
                return res.json();
            })
            .then(function(data) {
                updateBadges(0);
                fetchNotifications();
            });
        });
    }

    function updateBadges(count) {
        var countInt = parseInt(count, 10) || 0;
        if (popoverUnreadCount) {
            popoverUnreadCount.textContent = countInt;
        }
        if (topBarBadge) {
            if (countInt === 0) {
                topBarBadge.classList.add('hidden');
                topBarBadge.textContent = '0';
            } else {
                topBarBadge.classList.remove('hidden');
                if (countInt > 99) {
                    topBarBadge.textContent = '99+';
                } else {
                    topBarBadge.textContent = countInt;
                }
            }
        }
    }

    function closePopover() {
        // 閉じる時はアニメーションクラスを元に戻して隠す
        popoverPanel.classList.remove('opacity-100', 'translate-y-0');
        popoverPanel.classList.add('opacity-0', '-translate-y-1');
        bellButton.setAttribute('aria-expanded', 'false');
        setTimeout(function() {
            popoverPanel.classList.add('hidden');
            popoverPanel.style.display = 'none';
        }, 150);
    }

    document.addEventListener('click', function(e) {
        if (popoverPanel && !popoverPanel.classList.contains('hidden')) {
            if (!popoverPanel.contains(e.target) && !bellButton.contains(e.target)) {
                closePopover();
            }
        }
    });
}
