module.exports = {
    globDirectory: 'public/',
    globPatterns: [
        '**/*.{html,css,js,json,png,jpg,jpeg,gif,svg,ico,webp,woff,woff2,ttf,eot}'
    ],
    swDest: 'public/service-worker.js',
    clientsClaim: true,
    skipWaiting: true,
    runtimeCaching: [
        {
            urlPattern: /\.(?:png|jpg|jpeg|svg|gif|webp)$/,
            handler: 'CacheFirst',
            options: {
                cacheName: 'images',
                expiration: {
                    maxEntries: 50,
                    maxAgeSeconds: 30 * 24 * 60 * 60
                }
            }
        },
        {
            urlPattern: /\.(?:js|css)$/,
            handler: 'StaleWhileRevalidate',
            options: {
                cacheName: 'static-resources',
                expiration: {
                    maxEntries: 60,
                    maxAgeSeconds: 7 * 24 * 60 * 60
                }
            }
        },
        {
            urlPattern: /^https:\/\/fonts\.(?:googleapis|gstatic)\.com/,
            handler: 'StaleWhileRevalidate',
            options: {
                cacheName: 'google-fonts',
                expiration: {
                    maxEntries: 10,
                    maxAgeSeconds: 60 * 60 * 24 * 365
                }
            }
        }
    ]
};