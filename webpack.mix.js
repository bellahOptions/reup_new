const mix = require('laravel-mix');
const path = require('path');

// Production plugins
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');
const { CleanWebpackPlugin } = require('clean-webpack-plugin');
const ImageMinimizerPlugin = require('image-minimizer-webpack-plugin');

// Mix configuration
mix.webpackConfig({
    mode: mix.inProduction() ? 'production' : 'development',
    
    // Enable source maps only in development
    devtool: mix.inProduction() ? false : 'source-map',
    
    optimization: {
        minimize: mix.inProduction(),
        minimizer: [
            // Minify JavaScript
            new TerserPlugin({
                terserOptions: {
                    compress: {
                        drop_console: true, // Remove console.log in production
                        drop_debugger: true, // Remove debugger statements
                        pure_funcs: ['console.log', 'console.info', 'console.debug'] // Remove specific console methods
                    },
                    mangle: {
                        keep_classnames: false,
                        keep_fnames: false,
                        toplevel: true,
                        safari10: false
                    },
                    format: {
                        comments: false, // Remove all comments
                        beautify: false // Don't beautify output
                    }
                },
                extractComments: false // Don't extract comments to separate file
            }),
            
            // Minify CSS
            new CssMinimizerPlugin({
                minimizerOptions: {
                    preset: [
                        'default',
                        {
                            discardComments: { removeAll: true }, // Remove all comments
                            normalizeWhitespace: true, // Remove whitespace
                            colormin: true, // Minimize colors
                            discardUnused: true, // Discard unused styles
                            mergeIdents: true, // Merge identifiers
                            reduceIdents: true, // Reduce identifiers
                            zindex: false // Don't optimize z-index
                        }
                    ]
                }
            })
        ],
        
        // Split chunks for better caching
        splitChunks: {
            chunks: 'all',
            minSize: 20000,
            maxSize: 244000,
            minChunks: 1,
            maxAsyncRequests: 30,
            maxInitialRequests: 30,
            automaticNameDelimiter: '~',
            cacheGroups: {
                defaultVendors: {
                    test: /[\\/]node_modules[\\/]/,
                    priority: -10,
                    reuseExistingChunk: true
                },
                default: {
                    minChunks: 2,
                    priority: -20,
                    reuseExistingChunk: true
                }
            }
        }
    },
    
    plugins: [
        // Clean output directory before build
        new CleanWebpackPlugin({
            cleanOnceBeforeBuildPatterns: [
                '**/*',
                '!mix-manifest.json',
                '!**/.gitignore'
            ],
            cleanStaleWebpackAssets: false,
            protectWebpackAssets: false
        }),
        
        // Optimize images (only in production)
        ...(mix.inProduction() ? [
            new ImageMinimizerPlugin({
                minimizer: {
                    implementation: ImageMinimizerPlugin.imageminMinify,
                    options: {
                        plugins: [
                            ['imagemin-mozjpeg', { quality: 80 }], // Compress JPEG
                            ['imagemin-pngquant', { quality: [0.65, 0.90] }], // Compress PNG
                            ['imagemin-svgo', { // Compress SVG
                                plugins: [
                                    {
                                        name: 'preset-default',
                                        params: {
                                            overrides: {
                                                removeViewBox: false,
                                                addAttributesToSVGElement: {
                                                    params: {
                                                        attributes: [
                                                            { xmlns: 'http://www.w3.org/2000/svg' }
                                                        ]
                                                    }
                                                }
                                            }
                                        }
                                    }
                                ]
                            }],
                            ['imagemin-gifsicle', { interlaced: true }], // Compress GIF
                            ['imagemin-webp', { quality: 80 }] // Convert to WebP
                        ]
                    }
                },
                generator: [
                    {
                        preset: 'webp',
                        implementation: ImageMinimizerPlugin.imageminGenerate,
                        options: {
                            plugins: ['imagemin-webp']
                        }
                    }
                ]
            })
        ] : [])
    ]
});

// Set public path
mix.setPublicPath('public');

// JavaScript processing with obfuscation
mix.js('resources/js/app.js', 'public/js')
    .vue() // If using Vue
    .minify('public/js/app.js') // Minify the output
    .babel(['public/js/app.js'], 'public/js/app.min.js') // Babel transpilation
    .options({
        terser: {
            terserOptions: {
                compress: {
                    drop_console: true,
                    drop_debugger: true
                },
                mangle: true,
                output: {
                    comments: false
                }
            }
        }
    });

// CSS processing with minification
mix.postCss('resources/css/app.css', 'public/css', [
    require('tailwindcss'), // If using Tailwind
    require('autoprefixer'),
])
.postCss('resources/css/admin.css', 'public/css') // Admin specific styles
.minify('public/css/app.css') // Minify CSS
.minify('public/css/admin.css');

// Versioning for cache busting
mix.version();

// Copy and optimize images
mix.copyDirectory('resources/images', 'public/images')
    .then(() => {
        if (mix.inProduction()) {
            console.log('Images optimized and copied!');
        }
    });

// Copy fonts
mix.copyDirectory('resources/fonts', 'public/fonts');

// Generate service worker (for PWA)
mix.generateSW({
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
                    maxAgeSeconds: 30 * 24 * 60 * 60 // 30 days
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
                    maxAgeSeconds: 7 * 24 * 60 * 60 // 7 days
                }
            }
        }
    ]
});

// Source maps only in development
if (!mix.inProduction()) {
    mix.sourceMaps();
}

// Custom webpack configuration for obfuscation
mix.webpackConfig({
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        plugins: mix.inProduction() ? [
                            ['transform-remove-console', { exclude: ['error', 'warn'] }],
                            'transform-remove-debugger'
                        ] : []
                    }
                }
            }
        ]
    }
});

// Success message
mix.then(() => {
    if (mix.inProduction()) {
        console.log('\n✅ Production build completed!');
        console.log('📦 Assets have been minified and optimized');
        console.log('🔒 JavaScript has been obfuscated');
        console.log('🎨 CSS has been minified');
        console.log('🖼️ Images have been optimized');
    }
});