/*
 * This file is part of a XenForo add-on.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

var SV = window.SV || {};
SV.AlertImprovements = SV.AlertImprovements || {};

(function($, window, document, _undefined) {
    "use strict";

    SV.AlertImprovements.AlertToggler = XF.Event.newHandler({
        eventNameSpace: 'SVAlertImprovementsAlertTogglerClick',
        eventType: 'click',

        options: {
            inListSelector: '.contentRow-figure--selector',
            successMessageFlashTimeOut: 3000
        },

        processing: null,

        init: function()
        {
            this.processing = false;
        },

        /**
         * @param {Event} e
         */
        click: function(e)
        {
            e.preventDefault();

            if (this.processing)
            {
                return;
            }
            this.processing = true;

            let self = this,
                $target = this.$target,
                $alert = $target.closest('.js-alert'),
                inList = $alert.find(this.options.inListSelector).length > 0;

            XF.ajax('POST', $target.attr('href'), {
                inlist: inList ? 1 : 0
            }, $.proxy(this, 'handleMarkReadAjax')).always(function ()
            {
                self.processing = false;
            });
        },

        /**
         * @param {Object} data
         */
        handleMarkReadAjax: function(data)
        {
            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            let $target = this.$target,
                $alert = $target.closest('.js-alert'),
                wasUnread = $alert.hasClass('is-unread'),
                inList = $alert.find(this.options.inListSelector).length > 0;

            $alert.removeClass('is-read');
            if (wasUnread)
            {
                $alert.addClass('is-recently-read').removeClass('is-unread');
            }
            else
            {
                $alert.removeClass('is-recently-read').addClass('is-unread');
            }

            if (data.html && data.html.content)
            {
                var id = $alert.data('alertId');
                if (id) {
                    var $replacementHtml = $(data.html.content);
                    var $replacementAlert = $replacementHtml.find("[data-alert-id='" + id + "']");
                    if ($replacementAlert.length) {
                        if (!inList) {
                            $replacementAlert.find(this.options.inListSelector).remove();
                        }
                        data.html.content = $replacementAlert;
                        XF.setupHtmlInsert(data.html, function ($html, data, onComplete) {
                            $alert.empty();
                            $alert.append($html);
                        });
                    }
                }
            }
        }
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

        init: function()
        {
            this.processing = false;
        },

        /**
         * @param {Event} e
         */
        click: function(e)
        {
            e.preventDefault();

            if (this.processing)
            {
                return;
            }
            this.processing = true;

            let self = this,
                inListSelector = this.options.inListSelector,
                $target = this.$target,
                listAlertIdLookup = {},
                listAlertIds = [],
                popupAlertIdLookup = {},
                popupAlertIds = [],
                $alerts = XF.findRelativeIf(this.options.alertItemSelector, this.$target);

            $alerts.each(function(){
                var $alert = $(this),
                    alertId = $alert.data('alertId'),
                    inList = $alert.find(inListSelector).length > 0;
                if (alertId) {
                    if (inList) {
                        if (!(alertId in listAlertIdLookup)) {
                            listAlertIdLookup[alertId] = 1
                            listAlertIds.push(alertId);
                        }
                    } else {
                        if (!(alertId in popupAlertIdLookup)) {
                            popupAlertIdLookup[alertId] = 1
                            popupAlertIds.push(alertId);
                        }
                    }
                }
            });

            if (listAlertIds.length) {
                XF.ajax('POST', $target.attr('href'), {
                    inlist: 1,
                    alert_ids: listAlertIds
                }, $.proxy(this, 'handleMarkAllReadAjaxList')).always(function () {
                    self.processing = false;
                });
            }
            if (popupAlertIds.length) {
                XF.ajax('POST', $target.attr('href'), {
                    inlist: 0,
                    alert_ids: popupAlertIds
                }, $.proxy(this, 'handleMarkAllReadAjaxPopup')).always(function () {
                    self.processing = false;
                });
            }
            if (!listAlertIds.length && !popupAlertIds.length) {
                this.processing = false;
            }
        },

        /**
         * @param {Object} data
         */
        handleMarkAllReadAjaxList: function(data)
        {
            this.handleMarkAllReadAjax(data, true);
        },

        /**
         * @param {Object} data
         */
        handleMarkAllReadAjaxPopup: function(data)
        {
            this.handleMarkAllReadAjax(data, false);
        },

        /**
         * @param {Object} data
         */
        handleMarkAllReadAjax: function(data, inlist)
        {
            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            var $replacementHtml = data.html && data.html.content ? $(data.html.content) : $('<div/>');
            var inListSelector = this.options.inListSelector;

            XF.findRelativeIf(this.options.alertItemSelector, this.$target).each(function () {
                let $alert = $(this),
                    wasUnread = $alert.hasClass('is-unread'),
                    wasNotInList = !inlist;

                if (wasUnread)
                {
                    $alert.removeClass('is-unread').addClass('is-recently-read');
                }
                else
                {
                    $alert.removeClass('is-read').removeClass('is-recently-read').addClass('is-unread');
                }

                var id = $alert.data('alertId');
                if (id) {
                    var $replacementAlert = $replacementHtml.find("[data-alert-id='" + id + "']");
                    if ($replacementAlert.length) {
                        if (wasNotInList) {
                            $replacementAlert.find(inListSelector).remove();
                        }
                        XF.setupHtmlInsert($replacementAlert.children(), function ($html, data, onComplete) {
                            $alert.empty();
                            $alert.append($html);
                        });
                    }
                }
            });
        }
    });

    XF.Click.register('sv-mark-alerts-read', 'SV.AlertImprovements.BulkMarkRead');
    XF.Click.register('mark-alert-unread', 'SV.AlertImprovements.AlertToggler');
} (jQuery, window, document));
