"use strict";

/**
 * @returns {boolean}
 */
var isBrowserSupportingSafariPushNotifications = function () {
    return typeof window.safari !== 'undefined'
        && typeof window.safari.pushNotification !== 'undefined'
        && typeof window.safari.pushNotification.permission === 'function'
        && typeof window.safari.pushNotification.requestPermission === 'function';
};

var triggerEventOnWindow = function (name) {
    triggerEventOn(name, window);
};

var triggerEventOn = function (name, element) {
    var event = document.createEvent('Event');
    event.initEvent(name, true /* can bubble*/, true /* cancelable */);
    element.dispatchEvent(event);
};

/**
 * This will send user decision to Apple and Apple will then send a POST or DELETE request to your \Granam\Safari\PushPackageController::devicesRegistrations
 * to add or remove user device, depending on user decision.
 *
 * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html#//apple_ref/doc/uid/TP40013225-CH3-SW1
 *
 * @param {string} webServiceUrl
 * @param {string} webServiceId
 * @param {string} userId
 * @param {Element} targetToAlsoTriggerEventOn
 * @return {boolean}
 */
var requestPermissionsForSafariPushNotifications = function (webServiceUrl, webServiceId, userId, targetToAlsoTriggerEventOn) {
    window.safari.pushNotification.requestPermission(webServiceUrl, webServiceId, {"user-id": userId}, function (response) {
        if (response.permission === 'granted') {
            triggerEventOnWindow('safariPushNotificationsPermissionsJustGranted');
            // deviceToken = response.deviceToken; we do not need it
            if (targetToAlsoTriggerEventOn) {
                triggerEventOn('safariPushNotificationsPermissionsJustGranted', targetToAlsoTriggerEventOn);
            }
        } else if (response.permission === 'denied') {
            triggerEventOnWindow('safariPushNotificationsPermissionsJustDenied');
            if (targetToAlsoTriggerEventOn) {
                triggerEventOn('safariPushNotificationsPermissionsJustDenied', targetToAlsoTriggerEventOn);
            }
        } else {
            triggerEventOnWindow('safariPushNotificationsPermissionsAreStillWaiting');
            if (targetToAlsoTriggerEventOn) {
                triggerEventOn('safariPushNotificationsPermissionsAreStillWaiting', targetToAlsoTriggerEventOn);
            }
        }
        triggerEventOnWindow('safariPushNotificationsPermissionsRequestEnd');
        if (targetToAlsoTriggerEventOn) {
            triggerEventOn('safariPushNotificationsPermissionsRequestEnd', targetToAlsoTriggerEventOn);
        }
    });
};

/**
 * @param {string} webServiceId
 * @param {string} webServiceUrl
 * @param {string} userId
 * @param {Element} targetToAlsoTriggerEventOn
 * @returns {boolean|null}
 * @throws {Error}
 */
function checkPermissionsForSafariPushNotifications(webServiceId, webServiceUrl, userId, targetToAlsoTriggerEventOn) {
    var permission = getStatusOfPermissionForPushNotification(webServiceId, webServiceUrl, userId);
    if (permission === false) {
        return false;
    }
    if (permission === 'default') { // user does not yet decide
        triggerEventOnWindow('safariPushNotificationsPermissionsRequestStart');
        requestPermissionsForSafariPushNotifications(webServiceUrl, webServiceId, userId, targetToAlsoTriggerEventOn);
        return null; // unknown yet - user decision will solve on fly and event catcher bind to targetToAlsoTriggerEventOn
    } else if (permission === 'granted') {
        triggerEventOnWindow('safariPushNotificationsPermissionsAlreadyGranted');
        // deviceToken = permissions.deviceToken; we do not need it
        return true;
    } else if (permission === 'denied') {
        triggerEventOnWindow('safariPushNotificationsPermissionsAlreadyDenied');
        return false;
    }
}

/**
 * @param webServiceId
 * @param webServiceUrl
 * @param userId
 * @return {boolean|string}
 * @throws {Error}
 */
function getStatusOfPermissionForPushNotification(webServiceId, webServiceUrl, userId) {
    if (!isBrowserSupportingSafariPushNotifications()) {
        return false;
    }
    if (!webServiceId.match(/web([.]\w+)+/)) {
        throw ('Invalid webServiceId, expected something like web.com.example.foo, got ' + webServiceId);
    }
    if (!webServiceUrl.match(/https?:\/\/\w*/)) {
        throw ('Invalid webServiceUrl, expected something like https://example.com, got ' + webServiceUrl);
    }
    var permissions = window.safari.pushNotification.permission(webServiceId);

    return permissions.permission;
}

/**
 * @param webServiceId
 * @param webServiceUrl
 * @param userId
 * @return {boolean}
 * @throws {Error}
 */
function hasUserAllowedPushNotifications(webServiceId, webServiceUrl, userId) {
    return getStatusOfPermissionForPushNotification(webServiceId, webServiceUrl, userId) === 'granted';
}

/**
 * @param {string} webServiceId you specified in Apple developer console
 * @param {string} webServiceUrl you specified in Apple developer console
 * @param {string} serverPushUrl This URL should lead to \Granam\Safari\PushPackageController::pushNotification
 * @param {string} userId per-user-unique ID of your choice
 * @param {string} title Heading shown to user in OS X notification
 * @param {string} text Main message shown to user in OS X notification
 * @param {string} buttonText
 */
function pushSafariNotification(webServiceId, webServiceUrl, serverPushUrl, userId, title, text, buttonText) {
    var _pushSafariNotification = function (serverPushUrl, userId, title, text, buttonText) {
        triggerEventOnWindow('sendingSafariPushNotification');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', serverPushUrl, true /* async */);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded'); // like form submit
        xhr.onload = function () {
            if (xhr.status !== 200) {
                triggerEventOnWindow('safariPushNotificationHasNotBeenSent');
                throw ('Pushing Safari notification failed with response code ' + xhr.status + ', message ' + xhr.statusText
                    + ' and headers ' + xhr.getAllResponseHeaders().join('; '));
            }
            triggerEventOnWindow('safariPushNotificationHasBeenSent');
        };
        var data = new FormData();
        data.set('title', title)
            .set('text', text)
            .set('button-text', buttonText)
            .set('user-id', userId);
        xhr.send(data);
    };
    var permissionsEventCatcher = document.createElement('span');
    permissionsEventCatcher.addEventListener(
        'safariPushNotificationsPermissionsJustGranted', // something-like-promise
        function () {
            _pushSafariNotification(serverPushUrl, userId, title, text, buttonText);
        },
        true
    );
    var permissionsGranted = checkPermissionsForSafariPushNotifications(webServiceId, webServiceUrl, userId, permissionsEventCatcher);
    if (permissionsGranted === false) {
        triggerEventOnWindow('safariPushNotificationHasNotBeenSent');
    }
    if (permissionsGranted === true) {
        _pushSafariNotification(serverPushUrl, userId, title, text, buttonText);
    }
    // null means we are waiting for user and permission-solving request to Apple - the permissionsEventCatcher will take care about result of that
}