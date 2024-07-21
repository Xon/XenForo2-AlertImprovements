var SV = window.SV || {};
SV.AlertImprovements = SV.AlertImprovements || {};
SV.$ = SV.$ || window.jQuery || null;
SV.extendObject = SV.extendObject || XF.extendObject || jQuery.extend;

;((window, document) =>
{
    'use strict';
    var $ = SV.$;

    SV.AlertImprovements.invalidSelectorsToRemove = [
        '.alert--unsummarize',
        '.alert--mark-read',
        '.alert--mark-unread',
    ];
    SV.AlertImprovements.updateAlert = (alert, replacementHtml, notInList, inListSelector) => {
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

        const id = alert.getAttribute('data-alert-id');
        if (id)
        {
            let replacementAlert = replacementHtml.querySelector("[data-alert-id='" + id + "']");
            if (!!replacementHtml && !!replacementAlert)
            {
                if (notInList)
                {
                    for (const el of replacementAlert.querySelectorAll(inListSelector))
                    {
                        el.remove();
                    }
                }

                if (typeof XF.createElement !== "function") // XF 2.2
                {
                    replacementAlert = $(replacementAlert);
                }

                XF.setupHtmlInsert(replacementAlert, (html, data, onComplete) => {
                    if (typeof XF.createElement !== "function")
                    {
                        html = html.get(0);
                    }

                    alert.replaceChildren();
                    alert.append(html);

                    onComplete();
                });
            }
        }
        else
        {
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
            inListSelector: '.contentRow-figure--selector',
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

            const target = (this.target || this.$target.get(0)),
                alert = target.closest('.js-alert'),
                inList = !!alert.querySelector(this.options.inListSelector);

            XF.ajax(
                'POST',
                target.getAttribute('href'),
                { inlist: inList ? 1 : 0 },
                this.handleMarkReadAjax.bind(this)
            );
        },

        /**
         * @param {Object} data
         */
        handleMarkReadAjax (data)
        {
            this.processing = false;

            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            let replacementHtml;
            if (typeof XF.createElement === "function")
            {
                replacementHtml = data.html && data.html.content
                    ? XF.createElementFromString(data.html.content)
                    : XF.createElementFromString('<div />')
                ;
            }
            else // jQuery - XF 2.2
            {
                replacementHtml = data.html && data.html.content
                    ? SV.$(data.html.content).get(0)
                    : SV.$('<div />').get(0)
                ;
            }

            const target = this.target || this.$target.get(0),
                alert = target.closest('.js-alert'),
                notInList = !(!!alert.querySelector(this.options.inListSelector)),
                inListSelector = this.options.inListSelector
            ;

            SV.AlertImprovements.updateAlert(alert, replacementHtml, notInList, inListSelector);
        },
    });

    SV.AlertImprovements.BulkMarkRead = XF.Event.newHandler({
        eventNameSpace: 'SVAlertImprovementsAlertListMarkReadClick',
        eventType: 'click',

        options: {
            successMessageFlashTimeOut: 3000,
            inListSelector: '.contentRow-figure--selector',
            alertItemSelector: '.js-alert.is-unread'
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

            const inListSelector = this.options.inListSelector,
                target = this.target || this.$target.get(0),
                targetAttr = target.getAttribute('href');
            let
                listAlertIdLookup = {},
                listAlertIds = [],
                popupAlertIdLookup = {},
                popupAlertIds = [],
                alerts = XF.findRelativeIf(this.options.alertItemSelector, target);

            if (!alerts || !alerts.length)
            {
                this.processing = false;
                return;
            }

            if (typeof XF.createElement !== "function")
            {
                alerts = alerts.get();
            }

            alerts.forEach((alert) => {
                let alertId = alert.getAttribute('data-alert-id'),
                    inList = !!alert.querySelector(inListSelector);
                if (alertId)
                {
                    if (inList)
                    {
                        if (!(alertId in listAlertIdLookup))
                        {
                            listAlertIdLookup[alertId] = 1
                            listAlertIds.push(alertId);
                        }
                    }
                    else
                    {
                        if (!(alertId in popupAlertIdLookup))
                        {
                            popupAlertIdLookup[alertId] = 1
                            popupAlertIds.push(alertId);
                        }
                    }
                }
            });

            if (listAlertIds.length)
            {
                XF.ajax(
                    'POST',
                    targetAttr,
                    { inlist: 1, alert_ids: listAlertIds},
                    this.handleMarkAllReadAjaxList.bind(this)
                );
            }
            if (popupAlertIds.length)
            {
                XF.ajax(
                    'POST',
                    targetAttr,
                    { inlist: 0, alert_ids: popupAlertIds},
                    this.handleMarkAllReadAjaxPopup.bind(this)
                );
            }

            if (!listAlertIds.length && !popupAlertIds.length)
            {
                this.processing = false;
            }
        },

        /**
         * @param {Object} data
         */
        handleMarkAllReadAjaxList (data)
        {
            this.processing = false;
            this.handleMarkAllReadAjax(data, true);
        },

        /**
         * @param {Object} data
         */
        handleMarkAllReadAjaxPopup (data)
        {
            this.processing = false;
            this.handleMarkAllReadAjax(data, false);
        },

        /**
         * @param {Object} data
         * @param {boolean} inlist
         */
        handleMarkAllReadAjax (data, inlist)
        {
            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            let alerts = XF.findRelativeIf(this.options.alertItemSelector, this.target || this.$target);

            if (!alerts || !alerts.length)
            {
                return;
            }

            // javascript load
            data.html.content = '<div/>';
            XF.setupHtmlInsert(data.html, () => {});

            let replacementHtml;
            if (typeof XF.createElement === "function")
            {
                replacementHtml = data.html && data.html.content
                    ? XF.createElementFromString(data.html.content)
                    : XF.createElementFromString('<div />')
                ;
            }
            else // jQuery - XF 2.2
            {
                replacementHtml = data.html && data.html.content
                    ? SV.$(data.html.content).get(0)
                    : SV.$('<div />').get(0)
                ;
                alerts = alerts.get();
            }

            const inListSelector = this.options.inListSelector,
                wasNotInList = !inlist;

            alerts.forEach((alert) => {
                SV.AlertImprovements.updateAlert(
                    alert,
                    replacementHtml,
                    wasNotInList,
                    inListSelector
                );
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
                id = alert.getAttribute('data-alert-id');
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
