// firebase-messaging-sw.js
// Give the service worker access to Firebase Messaging.
// Note that you can only use JS that works in a worker (no DOM access)

importScripts('https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.6.1/firebase-messaging-compat.js');

// Initialize the Firebase app in the service worker by passing in
// your app's Firebase config object.
// https://firebase.google.com/docs/web/setup#config-object
firebase.initializeApp({
    apiKey: "AIzaSyDH9UQn35OLevEfy9R6hq1Ri5_nNJ0Yw_o",
    authDomain: "adms-hospital.firebaseapp.com",
    projectId: "adms-hospital",
    storageBucket: "adms-hospital.firebasestorage.app",
    messagingSenderId: "197174822327",
    appId: "1:197174822327:web:284630ca81af2da1155432"
});

// Retrieve an instance of Firebase Messaging so that it can handle background
// messages.
const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
    console.log('[firebase-messaging-sw.js] Received background message ', payload);
    // Customize notification here
    const notificationTitle = payload.notification.title;
    const notificationOptions = {
        body: payload.notification.body,
        icon: '/assets/img/logo.png'
    };

    self.registration.showNotification(notificationTitle, notificationOptions);
});
