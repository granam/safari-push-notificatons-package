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
 * @param {string} websitePushId
 * @param {string} userId
 * @param {Element} targetToAlsoTriggerEventOn
 * @return {boolean}
 */
var requestPermissionsForSafariPushNotifications = function (webServiceUrl, websitePushId, userId, targetToAlsoTriggerEventOn) {
    window.safari.pushNotification.requestPermission(webServiceUrl, websitePushId, {"user-id": userId}, function (response) {
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
 * @param {string} websitePushId
 * @param {string} webServiceUrl
 * @param {string} userId
 * @param {Element} targetToAlsoTriggerEventOn
 * @returns {boolean|null}
 * @throws {Error}
 */
function checkPermissionsForSafariPushNotifications(websitePushId, webServiceUrl, userId, targetToAlsoTriggerEventOn) {
    var permission = getStatusOfPermissionForPushNotification(websitePushId, webServiceUrl, userId);
    if (permission === false) {
        return false;
    }
    if (permission === 'default') { // user does not yet decide
        triggerEventOnWindow('safariPushNotificationsPermissionsRequestStart');
        requestPermissionsForSafariPushNotifications(webServiceUrl, websitePushId, userId, targetToAlsoTriggerEventOn);
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
 * @param {string} websitePushId
 * @param {string} webServiceUrl
 * @param {string} userId
 * @return {boolean|string}
 * @throws {Error}
 */
function getStatusOfPermissionForPushNotification(websitePushId, webServiceUrl, userId) {
    if (!isBrowserSupportingSafariPushNotifications()) {
        return false;
    }
    if (typeof websitePushId === 'undefined') {
        throw ('Missing websitePushId');
    }
    if (!websitePushId.match(/web([.]\w+)+/)) {
        throw ('Invalid websitePushId, expected something like web.com.example.foo, got ' + websitePushId);
    }
    if (typeof webServiceUrl === 'undefined') {
        throw ('Missing webServiceUrl');
    }
    if (!webServiceUrl.match(/https?:\/\/\w*/)) {
        throw ('Invalid webServiceUrl, expected something like https://example.com, got ' + webServiceUrl);
    }
    var permissions = window.safari.pushNotification.permission(websitePushId);

    return permissions.permission;
}

/**
 * @param {string} websitePushId
 * @param {string} webServiceUrl
 * @param {string} userId
 * @return {boolean}
 * @throws {Error}
 */
function hasUserAllowedPushNotifications(websitePushId, webServiceUrl, userId) {
    return getStatusOfPermissionForPushNotification(websitePushId, webServiceUrl, userId) === 'granted';
}

/**
 * @param {string} websitePushId
 * @param {string} webServiceUrl
 * @param {string} userId
 * @return {boolean}
 * @throws {Error}
 */
function hasUserDeniedPushNotifications(websitePushId, webServiceUrl, userId) {
    return getStatusOfPermissionForPushNotification(websitePushId, webServiceUrl, userId) === 'denied';
}

/**
 * @param {string} websitePushId
 * @param {string} webServiceUrl
 * @param {string} userId
 * @return {boolean}
 * @throws {Error}
 */
function hasUserChoosedPushNotifications(websitePushId, webServiceUrl, userId) {
    var status = getStatusOfPermissionForPushNotification(websitePushId, webServiceUrl, userId);

    return status === 'granted' || status === 'denied';
}

/**
 * @param {string} websitePushId you specified in Apple developer console
 * @param {string} webServiceUrl you specified in Apple developer console
 * @param {string} serverPushUrl This URL should lead to \Granam\Safari\PushPackageController::pushNotification
 * @param {string} userId per-user-unique ID of your choice
 * @param {string} title Heading shown to user in OS X notification
 * @param {string} text Main message shown to user in OS X notification
 * @param {string=} buttonText
 */
function pushSafariNotification(websitePushId, webServiceUrl, serverPushUrl, userId, title, text, buttonText) {
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
    var permissionsGranted = checkPermissionsForSafariPushNotifications(websitePushId, webServiceUrl, userId, permissionsEventCatcher);
    if (permissionsGranted === false) {
        triggerEventOnWindow('safariPushNotificationHasNotBeenSent');
    }
    if (permissionsGranted === true) {
        _pushSafariNotification(serverPushUrl, userId, title, text, buttonText);
    }
    // null means we are waiting for user and permission-solving request to Apple - the permissionsEventCatcher will take care about result of that
}