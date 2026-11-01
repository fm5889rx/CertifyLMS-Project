/**
 * JSフロント通知ポップオーバー（実機404完全解決・モダン決定版）
 */
export function initNotificationPopover() {
    const bellButton = document.querySelector(
        "[data-notification-popover-trigger]",
    );
    const popoverPanel = document.getElementById("notification-popover-panel");
    const topBarBadge = document.querySelector(
        "[data-notification-popover-badge]",
    );

    const popoverUnreadCount = document.querySelector(
        "[data-notification-popover-unread-count]",
    );
    const markAllButton = document.querySelector(
        "[data-notification-popover-mark-all]",
    );
    const listContainer = document.querySelector(
        "[data-notification-popover-items]",
    );
    const loadingSpinner = document.querySelector(
        "[data-notification-popover-loading]",
    );
    const emptyState = document.querySelector(
        "[data-notification-popover-empty]",
    );

    const tabAllButton = document.querySelector(
        '[data-notification-popover-tab="all"]',
    );
    const tabUnreadButton = document.querySelector(
        '[data-notification-popover-tab="unread"]',
    );
    const rowTemplate = document.querySelector(
        "[data-notification-popover-row-template]",
    );

    if (!bellButton || !popoverPanel) {
        return;
    }

    let currentTab = "all";

    const getCsrfToken = () => {
        const matches = document.cookie.match(
            new RegExp("(?:^|; )XSRF-TOKEN=([^;]*)"),
        );
        return matches ? decodeURIComponent(matches[1]) : "";
    };

    // ベルアイコン開閉トグル制御
    bellButton.addEventListener("click", (e) => {
        e.preventDefault();
        const isHidden = popoverPanel.classList.contains("hidden");

        if (isHidden) {
            popoverPanel.classList.remove("hidden");
            popoverPanel.style.display = "flex";
            bellButton.setAttribute("aria-expanded", "true");

            popoverPanel.classList.remove("opacity-0", "-translate-y-1");
            popoverPanel.classList.add("opacity-100", "translate-y-0");

            fetchNotifications();
        } else {
            closePopover();
        }
    });

    if (tabAllButton) {
        tabAllButton.addEventListener("click", (e) => {
            e.stopPropagation();
            currentTab = "all";
            tabAllButton.setAttribute("aria-selected", "true");
            tabAllButton.classList.add(
                "bg-white",
                "text-primary-700",
                "shadow-sm",
            );
            if (tabUnreadButton) {
                tabUnreadButton.setAttribute("aria-selected", "false");
                tabUnreadButton.classList.remove(
                    "bg-white",
                    "text-primary-700",
                    "shadow-sm",
                );
            }
            fetchNotifications();
        });
    }

    if (tabUnreadButton) {
        tabUnreadButton.addEventListener("click", (e) => {
            e.stopPropagation();
            currentTab = "unread";
            tabUnreadButton.setAttribute("aria-selected", "true");
            tabUnreadButton.classList.add(
                "bg-white",
                "text-primary-700",
                "shadow-sm",
            );
            if (tabAllButton) {
                tabAllButton.setAttribute("aria-selected", "false");
                tabAllButton.classList.remove(
                    "bg-white",
                    "text-primary-700",
                    "shadow-sm",
                );
            }
            fetchNotifications();
        });
    }

    function fetchNotifications() {
        if (loadingSpinner) loadingSpinner.classList.remove("hidden");
        if (listContainer) listContainer.innerHTML = "";
        if (emptyState) emptyState.classList.add("hidden");

        fetch("/api/v1/notifications?tab=" + currentTab + "&per_page=5", {
            method: "GET",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
            },
            credentials: "include",
        })
            .then((res) => res.json())
            .then((data) => {
                if (loadingSpinner) loadingSpinner.classList.add("hidden");
                updateBadges(data.unread_count);

                const notifications = data.notifications || [];

                if (notifications.length === 0) {
                    if (emptyState) emptyState.classList.remove("hidden");
                    return;
                }

                notifications.forEach((item) => {
                    const clone = rowTemplate.content.cloneNode(true);
                    const rowLink = clone.querySelector(
                        "[data-notification-popover-row]",
                    );
                    const dot = clone.querySelector(
                        "[data-notification-popover-row-dot]",
                    );
                    const title = clone.querySelector(
                        "[data-notification-popover-row-title]",
                    );
                    const msg = clone.querySelector(
                        "[data-notification-popover-row-message]",
                    );
                    const time = clone.querySelector(
                        "[data-notification-popover-row-time]",
                    );

                    if (title) title.textContent = item.title;
                    if (msg) msg.textContent = item.message;
                    if (time) time.textContent = item.time;

                    if (dot && item.is_unread === false) {
                        dot.remove();
                    }
                    if (rowLink && item.is_unread === true) {
                        rowLink.setAttribute("data-unread", "true");
                    }

                    if (rowLink) {
                        rowLink.addEventListener("click", (e) => {
                            e.preventDefault();
                            closePopover();

                            if (item.is_unread === true) {
                                // 💡【最重要解決】：Bladeコンパイラの誤爆を防ぐため、バックティックを廃止
                                // 💡 100%安全な通常の「文字結合（+）」によるAPIエンドポイントURLの組み立てに完全復元！
                                fetch(
                                    "/api/v1/notifications/" +
                                        item.id +
                                        "/read",
                                    {
                                        method: "POST",
                                        headers: {
                                            "X-XSRF-TOKEN": getCsrfToken(),
                                            Accept: "application/json",
                                        },
                                        credentials: "include",
                                    },
                                );
                            }
                            window.location.href = item.action_url;
                        });
                    }
                    listContainer.appendChild(clone);
                });
            });
    }

    if (markAllButton) {
        markAllButton.addEventListener("click", (e) => {
            e.preventDefault();
            fetch("/api/v1/notifications/read-all", {
                method: "POST",
                headers: {
                    "X-XSRF-TOKEN": getCsrfToken(),
                    Accept: "application/json",
                },
                credentials: "include",
            })
                .then((res) => res.json())
                .then(() => {
                    updateBadges(0);
                    fetchNotifications();
                });
        });
    }

    function updateBadges(count) {
        const countInt = parseInt(count, 10) || 0;
        if (popoverUnreadCount) {
            popoverUnreadCount.textContent = countInt;
        }
        if (topBarBadge) {
            if (countInt === 0) {
                topBarBadge.classList.add("hidden");
                topBarBadge.textContent = "0";
            } else {
                topBarBadge.classList.remove("hidden");
                topBarBadge.textContent = countInt > 99 ? "99+" : countInt;
            }
        }
    }

    function closePopover() {
        popoverPanel.classList.remove("opacity-100", "translate-y-0");
        popoverPanel.classList.add("opacity-0", "-translate-y-1");
        bellButton.setAttribute("aria-expanded", "false");
        setTimeout(() => {
            popoverPanel.classList.add("hidden");
            popoverPanel.style.display = "none";
        }, 150);
    }

    document.addEventListener("click", (e) => {
        if (popoverPanel && !popoverPanel.classList.contains("hidden")) {
            if (
                !popoverPanel.contains(e.target) &&
                !bellButton.contains(e.target)
            ) {
                closePopover();
            }
        }
    });
}
