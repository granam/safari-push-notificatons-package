# Apple OS X (Mac OS) Safari push notifications

Heavily inspired by [connorlacombe/Safari-Push-Notifications/](https://github.com/connorlacombe/Safari-Push-Notifications/).

## Flow

On your Javascript command, by calling
```js
pushSafariNotification(webServiceId, webServiceUrl, serverPushUrl, userId, title, text, buttonText)
```
- browser will check if is Safari of sufficient version ([OS X v10.9 and later](https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html))
- if user already allowed push notifications
    - then push notification **is** send
- else if user already denied push notifications
    - then push notification is **not** sent (to find out if push notifications can be sent, call JS function `hasUserAllowedPushNotifications`)
- if user does not YET decided to add or deny permissions to your side to send a push notification, it will
    - trigger JS event `safariPushNotificationsPermissionsRequestStart` on window JS object
    - then asks user for permissions to accept push notifications from your side via Safari build-in layout
        - then, if user *agreed*, Apple will send POST request to `\Granam\Safari\PushPackageController::pushPackages` by calling an URL you set via Apple developer console
        - then Apple will send user decision to `\Granam\Safari\PushPackageController::devicesRegistrations` by calling an URL you set via Apple developer console
        - then - if user *agreed*
                - thenJS event `safariPushNotificationsPermissionsJustGranted` is triggered on window object
                - then **push notification is send**
            - else if user *declined*, then JS event `safariPushNotificationsPermissionsJustDenied` is triggered on window object and push notification is **not** sent
    - then JS event `safariPushNotificationsPermissionsRequestEnd` is triggered on window object