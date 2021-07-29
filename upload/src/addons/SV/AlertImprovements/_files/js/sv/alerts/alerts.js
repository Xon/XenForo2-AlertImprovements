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

    SV.AlertImprovements.updateAlert = function($alert, $replacementHtml, notInList, inListSelector) {
        var wasUnread = $alert.hasClass('is-unread');

        $alert.removeClass('is-read');
        if (wasUnread) {
            $alert.addClass('is-recently-read').removeClass('is-unread');
        } else {
            $alert.removeClass('is-recently-read').addClass('is-unread');
        }

        var id = $alert.data('alertId');
        if (id) {
            var $replacementAlert = $replacementHtml.find("[data-alert-id='" + id + "']");
            if ($replacementAlert.length) {
                if (notInList) {
                    $replacementAlert.find(inListSelector).remove();
                }
                XF.setupHtmlInsert($replacementAlert, function ($html, data, onComplete) {
                    $alert.empty();
                    $alert.append($html.children());
                });
            }
        } else {
            // invalid alert, remove various per-alert links for it
            $alert.find('.alert--unsummarize').remove();
            $alert.find('.alert--mark-read').remove();
            $alert.find('.alert--mark-unread').remove();
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

            var self = this,
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

            var $target = this.$target,
                $alert = $target.closest('.js-alert'),
                notInList = !$alert.find(this.options.inListSelector).length,
                inListSelector = this.options.inListSelector,
                $replacementHtml = data.html && data.html.content ? $(data.html.content) : $('<div/>');

            SV.AlertImprovements.updateAlert($alert, $replacementHtml, notInList, inListSelector);
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

            var self = this,
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
         * @param {boolean} inlist
         */
        handleMarkAllReadAjax: function(data, inlist)
        {
            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            var $replacementHtml = data.html && data.html.content ? $(data.html.content) : $('<div/>'),
                inListSelector = this.options.inListSelector,
                wasNotInList = !inlist;
            // javascript load
            data.html.content = '<div/>';
            XF.setupHtmlInsert(data.html, function ($html, data, onComplete) {
            });

            XF.findRelativeIf(this.options.alertItemSelector, this.$target).each(function () {
                SV.AlertImprovements.updateAlert($(this), $replacementHtml, wasNotInList, inListSelector);
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

        init: function()
        {
            this.processing = false;
        },

        /**
         * @param {Event} e
         */
        click: function(e) {
            e.preventDefault();

            if (this.processing) {
                return;
            }
            this.processing = true;
            var $target = this.$target;

            XF.ajax('POST', $target.attr('href'), {
                // todo: does anything need to be submitted?
            }, $.proxy(this, 'handleReloadAlertsPopupList')).always(function () {
                self.processing = false;
            });
        },

        /**
         * @param {Object} data
         */
        handleReloadAlertsPopupList: function(data) {
            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            var $target = this.$target,
                $alert = $target.closest('.js-alert');

            var id = $alert.data('alertId');
            if (id) {
                $(".js-alert[data-alert-id='" + id + "']").remove();
            }

            //var $menu = $(this.options.alertPopupSelector);
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

        init: function () {
            var options = this.options,
                alerts = this.$target.find(options.classSelector);
            if (alerts.length === 0) {
                return;
            }
            options.transitionDuration = Math.max(0, options.transitionDuration | 0);
            options.transitionDelay = Math.max(0, options.transitionDelay | 0);
            if (options.transitionDuration) {
                setTimeout(function()
                {
                    alerts
                        .css({'transition-duration': options.transitionDuration + 's'})
                        .removeClassTransitioned(options.classToRemove)
                        .addClassTransitioned(options.classToAdd);
                }, options.transitionDelay * 1000);
            } else {
                alerts
                    .addRemove(options.classToRemove)
                    .addClass(options.classToAdd)
            }
        }
    });

    XF.Click.register('sv-mark-alerts-read', 'SV.AlertImprovements.BulkMarkRead');
    XF.Click.register('mark-alert-unread', 'SV.AlertImprovements.AlertToggler');
    XF.Click.register('unsummarize-alert', 'SV.AlertImprovements.AlertUnsummarize');
    XF.Element.register('fade-read-alerts', 'SV.AlertImprovements.FadeReadAlerts');
} (jQuery, window, document));
