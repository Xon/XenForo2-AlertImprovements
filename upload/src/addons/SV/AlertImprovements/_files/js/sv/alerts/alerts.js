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

    SV.AlertImprovements.MarkAlertsRead = XF.Event.newHandler({
        eventNameSpace: 'SVAlertImprovementsMarkAlertsReadClick',
        eventType: 'click',

        options: {
            alertItemSelector: '< .block | .js-alert'
        },

        processing: null,

        init: function ()
        {
            this.processing = false;
        },

        click: function (e)
        {
            e.preventDefault();

            if (this.processing)
            {
                return;
            }
            this.processing = true;

            let self = this,
                $target = this.$target;

            XF.ajax('POST', $target.attr('href'), {}, $.proxy(this, 'handleMarkAllReadAjax')).always(function ()
            {
                self.processing = false;
            });
        },

        /**
         * @param {Object} data
         */
        handleMarkAllReadAjax: function(data)
        {
            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            XF.findRelativeIf(this.options.alertItemSelector, this.$target).each(function ()
            {
                let $alert = $(this),
                    $alertToggle = $alert.find('.js-alertToggle'),
                    tooltip = XF.Element.getHandler($alertToggle, 'tooltip'),
                    phrase = $alertToggle.length ? $alertToggle.data('unread') : null;

                $alert.removeClass('is-unread').removeClass('block-row--highlighted');

                if ($alertToggle.length)
                {
                    $alertToggle.data('content', phrase).attr('data-content', phrase);
                }

                if (tooltip)
                {
                    tooltip.content = phrase;
                }
            });
        }
    });

    XF.Click.register('sv-alert-improvements--mark-alerts-read', 'SV.AlertImprovements.MarkAlertsRead');
} (jQuery, window, document));
