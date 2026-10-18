self.addEventListener('push', event => {

    let data = {
        title: 'EduMint24',
        body: 'New blog post available!',
        image: 'https://edumint24.com/assets/img/seo_og_default.png',
        url: 'https://edumint24.com/categories'
    };

    if (event.data) {
        data = event.data.json();
    }

    event.waitUntil(

        self.registration.showNotification(data.title, {

            body: data.body,

            icon: 'https://edumint24.com/assets/icons/android-icon-192x192.png',

            badge: 'https://edumint24.com/assets/badge/ic_stat_notifications_active/res/drawable-xhdpi/ic_stat_notifications_active.png',

            image: data.image,

            vibrate: [200, 100, 200],

            tag: 'edumint24-notification',

            renotify: true,

            requireInteraction: false,

            data: {
                url: data.url || 'https://edumint24.com',
                whatsappUrl: `https://api.whatsapp.com/send?text=${encodeURIComponent(data.title + ' ' + data.url)}`
            },

            actions: [
                {
                    action: 'open',
                    title: 'Open'
                },
                {
                    action: 'whatsapp',
                    title: 'WhatsApp'
                }
            ]

        })

    );

});

self.addEventListener('notificationclick', event => {

    event.notification.close();

    const data = event.notification.data;

    let targetUrl = data.url || 'https://edumint24.com';

    if (event.action === 'whatsapp') {

        targetUrl = data.whatsappUrl;

    }

    event.waitUntil(

        clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        }).then(windowClients => {

            for (const client of windowClients) {

                if (client.url === targetUrl && 'focus' in client) {
                    return client.focus();
                }

            }

            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }

        })

    );

});