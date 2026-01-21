// fcm_init.js
// Client-side Firebase Cloud Messaging Initialization

// TODO: Replace with your actual Firebase Project Configuration
// Get this from Firebase Console -> Project Settings -> General -> Your Apps -> Web App
const firebaseConfig = {
    apiKey: "AIzaSyDH9UQn35OLevEfy9R6hq1Ri5_nNJ0Yw_o",
    authDomain: "adms-hospital.firebaseapp.com",
    projectId: "adms-hospital",
    storageBucket: "adms-hospital.firebasestorage.app",
    messagingSenderId: "197174822327",
    appId: "1:197174822327:web:284630ca81af2da1155432"
};

// Check if Firebase is supported and config is set
if (firebaseConfig.apiKey && 'serviceWorker' in navigator) {
    // Import Firebase Scripts dynamically (using CDN for vanilla JS support without bundlers)

    import('https://www.gstatic.com/firebasejs/9.6.1/firebase-app.js').then((firebaseApp) => {
        import('https://www.gstatic.com/firebasejs/9.6.1/firebase-messaging.js').then((firebaseMessaging) => {

            const app = firebaseApp.initializeApp(firebaseConfig);
            const messaging = firebaseMessaging.getMessaging(app);

            // Request Permission
            Notification.requestPermission().then((permission) => {
                if (permission === 'granted') {
                    console.log('Notification permission granted.');

                    // Explicitly Register Service Worker (Fix for "no active Service Worker")
                    navigator.serviceWorker.register('/firebase-messaging-sw.js');

                    // WAIT for the Service Worker to be Ready (Active)
                    navigator.serviceWorker.ready.then((registration) => {
                        console.log('Service Worker Ready with scope:', registration.scope);

                        // Get Token using the READY registration
                        firebaseMessaging.getToken(messaging, {
                            serviceWorkerRegistration: registration
                            // vapidKey removed (optional, was causing InvalidAccessError due to placeholder)
                        }).then((currentToken) => {
                            if (currentToken) {
                                console.log('FCM Token Generated:', currentToken);
                                saveTokenToServer(currentToken);
                            } else {
                                console.log('No registration token available.');
                            }
                        }).catch((err) => {
                            console.error('An error occurred while retrieving token. ', err);
                        });
                    });

                } else {
                    console.log('Unable to get permission to notify.');
                }
            });

            // Handle incoming messages when in foreground
            firebaseMessaging.onMessage(messaging, (payload) => {
                console.log('Message received. ', payload);
                // Customize UI here, e.g. show a toast
                const notificationTitle = payload.notification.title;
                const notificationOptions = {
                    body: payload.notification.body,
                    icon: '/assets/img/logo.png'
                };

                // Show browser notification if possible
                if (Notification.permission === 'granted') {
                    new Notification(notificationTitle, notificationOptions);
                } else {
                    alert(notificationTitle + ": " + notificationOptions.body);
                }
            });

        });
    });
} else {
    console.log("FCM Setup: ServiceWorker not supported or Config missing.");
}

function saveTokenToServer(token) {
    fetch('/api_save_fcm_token.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ token: token }),
    })
        .then(response => response.json())
        .then(data => {
            console.log('Token sent to server:', data);
        })
        .catch((error) => {
            console.error('Error sending token:', error);
        });
}
