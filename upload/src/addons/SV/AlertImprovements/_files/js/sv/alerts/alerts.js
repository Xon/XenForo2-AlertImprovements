// noinspection ES6ConvertVarToLetConst
var SV = window.SV || {};
SV.AlertImprovements = SV.AlertImprovements || {};
SV.$ = SV.$ || window.jQuery || null;
SV.extendObject = SV.extendObject || XF.extendObject || jQuery.extend;

;((window, document) =>
{
    'use strict';
    "use strict";
    const $ = SV.$, xf22 = typeof XF.on !== 'function';

    SV.AlertImprovements.invalidSelectorsToRemove = [
        '.alert--unsummarize',
        '.alert--mark-read',
        '.alert--mark-unread',
    ];

    /**
     * @param {HTMLElement} alert
     * @param {HTMLElement | undefined} replacementHtml
     * @param {boolean} canRemove
     */
    SV.AlertImprovements.updateAlert = (alert, replacementHtml, canRemove) => {
        const wasUnread = alert.classList.contains('is-unread');

        alert.classList.remove('is-read');
        if (wasUnread)
        {
            alert.classList.add('is-recently-read');
            alert.classList.remove('is-unread');
        }
        else
        {
            alert.classList.remove('is-recently-read');
            alert.classList.add('is-unread');
        }

        const id = alert.dataset.alertId | 0;
        if (id && replacementHtml) {
            alert.replaceChildren(replacementHtml);
        } else if (canRemove) {
            alert.remove();
        } else {
            // invalid alert, remove various per-alert links for it
            SV.AlertImprovements.invalidSelectorsToRemove.forEach((selector) => {
                let tmp = alert.querySelector(selector);
                if (tmp) {
                    tmp.remove();
                }
            });
        }
    }

    SV.AlertImprovements.AlertToggler = XF.Event.newHandler({
        eventNameSpace: 'SVAlertImprovementsAlertTogglerClick',
        eventType: 'click',

        options: {
            successMessageFlashTimeOut: 3000,
        },

        processing: null,

        init ()
        {
            this.processing = false;
        },

        /**
         * @param {Event} e
         */
        click (e)
        {
            e.preventDefault();

            const target = (this.target || this.$target.get(0)),
                alert = target.closest('.js-alert'),
                alertId = alert.dataset.alertId | 0;
            if (!alertId) {
                return;
            }

            if (this.processing)
            {
                return;
            }
            this.processing = true;

            const list = !!document.querySelector(".js-alert[data-alert-id='" + alertId + "'] > [data-pop-up='0']");
            const popup = !!document.querySelector(".js-alert[data-alert-id='" + alertId + "'] > [data-pop-up='1']");

            XF.ajax(
                'POST',
                target.getAttribute('href'),
                { list: list ? 1 : 0, popup: popup ? 1 : 0 },
                this.handleMarkReadAjax.bind(this)
            );
        },

        /**
         * @param {Object} data
         */
        handleMarkReadAjax (data)
        {
            this.processing = false;

            const target = (this.target || this.$target.get(0)),
                alert = target.closest('.js-alert'),
                alertId = alert.dataset.alertId | 0;
            if (!alertId) {
                return;
            }

            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            XF.setupHtmlInsert(data.html, (html) => {
                if (xf22) {
                    html = html.get(0);
                }

                let inListAlert = html.querySelector("[data-alert-id='" + alertId + "'] > [data-pop-up='0']");
                if (inListAlert) {
                    const inListAlertsToUpdate = document.querySelectorAll(".js-alert[data-alert-id='" + alertId + "'] > [data-pop-up='0']");
                    inListAlertsToUpdate.forEach((el) => {
                        const alert = el.parentElement;
                        SV.AlertImprovements.updateAlert(alert, inListAlert, false);
                    });
                }

                let inPopupAlert =  html.querySelector("[data-alert-id='" + alertId + "'] > [data-pop-up='1']");
                if (inPopupAlert) {
                    const inPopupAlertsToUpdate = document.querySelectorAll(".js-alert[data-alert-id='" + alertId + "'] > [data-pop-up='1']");
                    inPopupAlertsToUpdate.forEach((el) => {
                        const alert = el.parentElement;
                        SV.AlertImprovements.updateAlert(alert, inPopupAlert, false);
                    });
                }

                return false;
            });
        },
    });

    SV.AlertImprovements.BulkMarkRead = XF.Event.newHandler({
        eventNameSpace: 'SVAlertImprovementsAlertListMarkReadClick',
        eventType: 'click',

        options: {
            successMessageFlashTimeOut: 3000,
        },

        processing: null,
        confirmOverlay: null,

        init ()
        {
            this.processing = false;
        },

        /**
         * @param {Event} e
         */
        click (e)
        {
            e.preventDefault();

            if (this.processing)
            {
                return;
            }
            this.processing = true;

            if (this.confirmOverlay) {
                this.confirmOverlay.destroy();
                this.confirmOverlay = null;
            }


            const target = this.target || this.$target.get(0),
                targetAttr = target.getAttribute('href'),
                listAlertIds = [],
                popupAlertIds = [],
                listAlerts = document.querySelectorAll(".js-alert.is-unread[data-alert-id] > [data-pop-up='0']"),
                popupAlerts = document.querySelectorAll(".js-alert.is-unread[data-alert-id] > [data-pop-up='1']");

            listAlerts.forEach((alert) => {
                const alertId = alert.parentElement.dataset.alertId | 0;
                if (alertId) {
                    listAlertIds.push(alertId);
                }
            });
            popupAlerts.forEach((alert) => {
                const alertId = alert.parentElement.dataset.alertId | 0;
                if (alertId) {
                    popupAlertIds.push(alertId);
                }
            });

            if (listAlertIds.length === 0 && popupAlertIds.length === 0)
            {
                this.processing = false;
                return;
            }

            XF.ajax(
                'POST',
                targetAttr,
                { list_alert_ids: listAlertIds, popup_alert_ids: popupAlertIds},
                this.handleMarkAllReadAjax.bind(this)
            );
        },

        /**
         * @param {Event} e
         * @param {object | undefined} data
         */
        handleOverlayConfirm (e, data) {
            data = data || e.data;
            e.preventDefault();

            if (this.confirmOverlay) {
                this.confirmOverlay.close();
            }

            this.handleMarkAllReadAjax(data);
        },

        handleOverlayClose (e, data) {
            data = data || e.data;
            e.preventDefault();

            if (this.confirmOverlay) {
                this.confirmOverlay.destroy();
                this.confirmOverlay = null;
            }
        },

        /**
         * @param {Object} data
         */
        handleMarkAllReadAjax (data)
        {
            this.processing = false;

            if ('unconfirmed' in data && data.unconfirmed) {

                XF.setupHtmlInsert(data.html, (html, container) =>
                {
                    const overlay = XF.getOverlayHtml({
                        html,
                        title: container.h1 || container.title,
                    })
                    this.confirmOverlay = XF.showOverlay(overlay);
                    /** @type HTMLElement **/
                    const overlayContainer = this.confirmOverlay.container || this.confirmOverlay.$container.get(0);
                    const form = overlayContainer.querySelector('form');
                    if (xf22) {
                        $(form).on('ajax-submit:response', this.handleOverlayConfirm.bind(this));
                        $(overlayContainer).on('overlay:hiding', this.handleOverlayClose.bind(this));
                    } else {
                        XF.on(form, 'ajax-submit:response', this.handleOverlayConfirm.bind(this))
                        XF.on(overlayContainer, 'overlay:hiding', this.handleOverlayClose.bind(this));
                    }
                });
                return;
            }

            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            if (data.status !== 'ok') {
                return;
            }

            if (data.redirect) {
                // just mark alert alerts as read
                document.querySelectorAll('.js-alert[data-alert-id]').forEach((alert) => {
                    SV.AlertImprovements.updateAlert(alert, null, false);
                });
                return;
            }

            XF.setupHtmlInsert(data.html, (html) => {
                if (xf22) {
                    html = html.get(0);
                }

                let alertsById = [];
                const inListAlerts = html.querySelectorAll("[data-alert-id] > [data-pop-up='0']");
                inListAlerts.forEach((alert) => {
                    const alertId = alert.parentElement.dataset.alertId | 0;
                    if (alertId) {
                        alertsById[alertId] = alert;
                    }
                });

                const inListAlertsToUpdate = document.querySelectorAll(".js-alert.is-unread[data-alert-id] > [data-pop-up='0']");
                inListAlertsToUpdate.forEach((el) => {
                    const alert = el.parentElement;
                    const alertId = alert.dataset.alertId | 0;
                    SV.AlertImprovements.updateAlert(alert, alertsById[alertId]);
                });

                alertsById = [];
                const inPopupAlerts = html.querySelectorAll(".js-alert.is-unread[data-alert-id] > [data-pop-up='1']");
                inPopupAlerts.forEach((alert) => {
                    const alertId = alert.parentElement.dataset.alertId | 0;
                    if (alertId) {
                        alertsById[alertId] = alert;
                    }
                });

                const inPopupAlertsToUpdate = document.querySelectorAll("[data-alert-id] > [data-pop-up='1']");
                inPopupAlertsToUpdate.forEach((el) => {
                    const alert = el.parentElement;
                    const alertId = alert.dataset.alertId | 0;
                    SV.AlertImprovements.updateAlert(alert, alertsById[alertId]);
                });

                return false;
            });
        }
    });

    SV.AlertImprovements.AlertUnsummarize = XF.Event.newHandler({
        eventNameSpace: 'SVAlertImprovementsAlertUnsummarizeClick',
        eventType: 'click',

        options: {
            alertPopupSelector: '.p-navgroup-link--alerts',
            successMessageFlashTimeOut: 3000
        },

        processing: null,

        init ()
        {
            this.processing = false;
        },

        /**
         * @param {Event} e
         */
        click (e)
        {
            e.preventDefault();

            if (this.processing)
            {
                return;
            }
            this.processing = true;
            const target = this.target || this.$target.get(0);

            XF.ajax(
                'POST',
                target.getAttribute('href'),
                // todo: does anything need to be submitted?
                {},
                this.handleReloadAlertsPopupList.bind(this)
            );
        },

        /**
         * @param {Object} data
         */
        handleReloadAlertsPopupList (data)
        {
            this.processing = false;

            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            const target = this.target || this.$target.get(0),
                alert = target.closest('.js-alert'),
                id = alert.dataset.alertId | 0;
            if (id)
            {
                const alerts = document.querySelectorAll(".js-alert[data-alert-id='" + id + "']");
                alerts.forEach((alert) => {
                    alert.remove();
                });
            }
        }
    });

    SV.AlertImprovements.FadeReadAlerts = 	XF.Element.newHandler({
        options: {
            classSelector: 'li.is-just-read',
            classToRemove: 'is-just-read',
            classToAdd: 'was-just-read',
            transitionDuration: 0,
            transitionDelay: 2,
        },

        init ()
        {
            const options = this.options,
                transitionDuration = Math.max(0, options.transitionDuration | 0),
                transitionDelay = Math.max(0, options.transitionDelay | 0),
                alerts = (this.target || this.$target.get(0)).querySelectorAll(options.classSelector);
            if (!alerts || !alerts.length)
            {
                return;
            }

            if (transitionDuration)
            {
                setTimeout(() => {
                    alerts.forEach((alert) => {
                        alert.style.transitionDuration = transitionDuration + 's';
                        if (typeof XF.Transition !== "undefined")
                        {
                            XF.Transition.removeClassTransitioned(alert, options.classToRemove);
                            XF.Transition.addClassTransitioned(alert, options.classToAdd);
                        }
                        else // jQuery - XF 2.2
                        {
                            $(alert)
                                .removeClassTransitioned(options.classToRemove)
                                .addClassTransitioned(options.classToAdd);
                        }
                    });
                }, transitionDelay * 1000);
            }
            else
            {
                alerts.forEach((alert) => {
                    alert.classList.add(options.classToAdd);
                    alert.classList.remove(options.classToRemove);
                });
            }
        },
    });

    XF.Element.extend('tooltip', {
        __backup: {
            'init': 'svAlertImprovementsTooltipAliasedInit',
        },

        options: SV.extendObject({}, XF.Tooltip.prototype.options, {
            positionOver: null,
        }),

        init ()
        {
            this.svAlertImprovementsTooltipAliasedInit();

            if (this.options.positionOver !== null)
            {
                this.tooltip.setPositioner(
                    XF.findRelativeIf(this.options.positionOver, this.target || this.$target)
                );
            }
        },
    });

    XF.Event.register('click', 'sv-mark-alerts-read', 'SV.AlertImprovements.BulkMarkRead');
    XF.Event.register('click', 'mark-alert-unread', 'SV.AlertImprovements.AlertToggler');
    XF.Event.register('click', 'unsummarize-alert', 'SV.AlertImprovements.AlertUnsummarize');
    XF.Element.register('fade-read-alerts', 'SV.AlertImprovements.FadeReadAlerts');
})(window, document)
