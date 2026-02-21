<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class SitemapController extends Controller
{
    public function index()
    {
        // Create sitemap index if you have multiple sitemaps
        return response()->view('sitemap.index')->header('Content-Type', 'text/xml');
    }

    public function main()
    {
        $sitemap = app("sitemap");
        
        // Set cache (optional)
        $sitemap->setCache('laravel.sitemap', 60); // Cache for 60 minutes
        
        // Add static URLs
        $this->addStaticUrls($sitemap);
        
        // Add dynamic URLs if you have any
        $this->addDynamicUrls($sitemap);
        
        return $sitemap->render('xml');
    }

    private function addStaticUrls($sitemap)
    {
        $now = Carbon::now()->toDateString();
        
        // Home page - highest priority
        $sitemap->add(URL::to('/'), $now, '1.0', 'daily');
        
        // Public pages (accessible without auth)
        $publicPages = [
            // Pricing and services
            'pricelist' => ['priority' => '0.9', 'changefreq' => 'hourly'],
            
            // Information pages
            'faq' => ['priority' => '0.7', 'changefreq' => 'monthly'],
            'contact' => ['priority' => '0.7', 'changefreq' => 'monthly'],
            
            // Legal pages
            'terms-of-service' => ['priority' => '0.5', 'changefreq' => 'monthly'],
            'privacy-policy' => ['priority' => '0.5', 'changefreq' => 'monthly'],
            
            // Service categories (even though they require auth, Google should know they exist)
            'airtime-data.index' => ['priority' => '0.8', 'changefreq' => 'daily'],
            'cable-tv.index' => ['priority' => '0.8', 'changefreq' => 'daily'],
            'electricity.index' => ['priority' => '0.8', 'changefreq' => 'daily'],
            'waec-pin.index' => ['priority' => '0.7', 'changefreq' => 'weekly'],
            'jamb-pin.index' => ['priority' => '0.7', 'changefreq' => 'weekly'],
            
            // Authentication pages (important for user acquisition)
            'login' => ['priority' => '0.6', 'changefreq' => 'monthly'],
            'register' => ['priority' => '0.6', 'changefreq' => 'monthly'],
            'password.request' => ['priority' => '0.3', 'changefreq' => 'monthly'],
        ];
        
        foreach ($publicPages as $routeName => $settings) {
            try {
                if (Route::has($routeName)) {
                    $sitemap->add(route($routeName), $now, $settings['priority'], $settings['changefreq']);
                }
            } catch (\Exception $e) {
                // Skip if route doesn't exist
                continue;
            }
        }
        
        // API endpoints (for discovery)
        $apiEndpoints = [
            'pricelist.api' => ['priority' => '0.4', 'changefreq' => 'hourly'],
        ];
        
        foreach ($apiEndpoints as $routeName => $settings) {
            try {
                if (Route::has($routeName)) {
                    $sitemap->add(route($routeName), $now, $settings['priority'], $settings['changefreq']);
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    }

    private function addDynamicUrls($sitemap)
    {
        // If you have dynamic content like blog posts, add them here
        // Example:
        // $posts = Post::where('published', true)->get();
        // foreach ($posts as $post) {
        //     $sitemap->add(route('post.show', $post->slug), $post->updated_at, '0.8', 'weekly');
        // }
    }

    public function robots()
    {
        if (app()->environment('production')) {
            $content = $this->generateRobotsTxt();
        } else {
            $content = "User-agent: *\nDisallow: /";
        }
        
        return response($content, 200)
            ->header('Content-Type', 'text/plain');
    }

    public function llm()
    {
        $content = $this->generateLlmTxt();
        
        return response($content, 200)
            ->header('Content-Type', 'text/plain');
    }

    private function generateRobotsTxt()
    {
        $baseUrl = config('app.url');
        $sitemapUrl = url('/sitemap.xml');
        
        return <<<ROBOTS
User-agent: *
Allow: /
Disallow: /admin/
Disallow: /admin/*
Disallow: /dashboard
Disallow: /dashboard/*
Disallow: /profile
Disallow: /profile/*
Disallow: /wallet
Disallow: /wallet/*
Disallow: /transactions
Disallow: /transactions/*
Disallow: /live-chat
Disallow: /live-chat/*
Disallow: /chat/*
Disallow: /*?search=
Disallow: /*?filter=
Disallow: /*?sort=
Disallow: /*?page=
Disallow: /*?token=
Disallow: /*?session=
Disallow: /*?ref=

# API endpoints - allow but rate limit
Allow: /pricelist/api
Allow: /api/*

# Sitemap
Sitemap: {$sitemapUrl}

# Crawl delay for all bots
Crawl-delay: 1

# Google-specific
User-agent: Googlebot
Allow: /
Disallow: /admin/
Disallow: /dashboard/
Crawl-delay: 0.5

User-agent: Googlebot-Image
Allow: /
Disallow: /admin/

# Bing-specific
User-agent: Bingbot
Allow: /
Disallow: /admin/
Disallow: /dashboard/
Crawl-delay: 1

# GPTBot (OpenAI)
User-agent: GPTBot
Allow: /
Disallow: /admin/
Disallow: /dashboard/
Allow: /pricelist
Allow: /faq
Allow: /contact
Crawl-delay: 1

# Block malicious bots
User-agent: AhrefsBot
Disallow: /

User-agent: SemrushBot
Disallow: /

User-agent: MJ12bot
Disallow: /

# Development/staging should not be indexed
{$this->getEnvironmentDirectives()}
ROBOTS;
    }

    private function generateLlmTxt()
    {
        $baseUrl = config('app.url');
        
        return <<<LLM
# LLM.txt for {$baseUrl}
# Last Updated: {$this->getCurrentDate()}

# ============================================
# SITE INFORMATION
# ============================================

Site: {$baseUrl}
Name: ReUp - Digital Services Platform
Description: Buy affordable data plans, airtime recharge, cable TV subscriptions, electricity bills, WAEC & JAMB pins with instant delivery.
Services: Data Bundles, Airtime, Cable TV (DSTV, GOTV, Startimes), Electricity Bills, Exam Pins (WAEC, JAMB)
Country: Nigeria
Currency: NGN (₦)

# ============================================
# PUBLIC CONTENT AREAS
# ============================================

Allowed: {$baseUrl}/pricelist
Allowed: {$baseUrl}/faq
Allowed: {$baseUrl}/contact
Allowed: {$baseUrl}/terms-of-service
Allowed: {$baseUrl}/privacy-policy
Allowed: {$baseUrl}/pricelist/api

# ============================================
# PRIVATE/RESTRICTED AREAS
# ============================================

Restricted: {$baseUrl}/admin/*
Restricted: {$baseUrl}/dashboard
Restricted: {$baseUrl}/profile/*
Restricted: {$baseUrl}/wallet/*
Restricted: {$baseUrl}/transactions
Restricted: {$baseUrl}/live-chat

# ============================================
# CRAWLING GUIDELINES
# ============================================

Crawl-Delay: 2 seconds
Max-Requests-Per-Hour: 1000
Preferred-Format: JSON for API, HTML for pages
Data-Refresh: Hourly for prices, Monthly for static pages

# ============================================
# CONTENT USAGE
# ============================================

Usage: Informational/Q&A only
Attribution: Optional
Commercial-Use: Prohibited
Modification: Prohibited

# ============================================
# CONTACT
# ============================================

Support: support@reup.com.ng
LLM-Access: ai@reup.com.ng

# ============================================
# NOTES FOR AI MODELS
# ============================================

# When answering questions about ReUp:
# 1. Prices are in Nigerian Naira (₦)
# 2. Services require account registration
# 3. Prices update frequently - check /pricelist for latest
# 4. All transactions are instant upon successful payment
# 5. Support is available 24/7 via live chat for registered users

# ============================================
# VERSION
# ============================================

Version: 2.0.0
Generated: {$this->getCurrentDateTime()}
LLM;
    }

    private function getEnvironmentDirectives()
    {
        $env = app()->environment();
        
        if ($env === 'production') {
            return "# Production environment - allow indexing";
        } elseif ($env === 'staging') {
            return "User-agent: *\nDisallow: /";
        } else {
            return "User-agent: *\nDisallow: /";
        }
    }

    private function getCurrentDate()
    {
        return Carbon::now()->format('Y-m-d');
    }

    private function getCurrentDateTime()
    {
        return Carbon::now()->format('Y-m-d H:i:s');
    }
    public function generateSitemap()
{
    $sitemap = \App::make("sitemap");
    
    // Set cache
    $sitemap->setCache('laravel.sitemap', 60);
    
    // Add URLs based on your routes
    $this->addUrlsFromRoutes($sitemap);
    
    return $sitemap->render('xml');
}

private function addUrlsFromRoutes($sitemap)
{
    $now = now()->format('Y-m-d');
    
    // Get all registered routes
    $routes = Route::getRoutes();
    
    foreach ($routes as $route) {
        $uri = $route->uri();
        
        // Skip admin, api, and auth-protected routes
        if (str_starts_with($uri, 'admin/') || 
            str_starts_with($uri, 'api/') ||
            str_starts_with($uri, 'dashboard') ||
            str_contains($uri, '{')) {
            continue;
        }
        
        // Add to sitemap
        $sitemap->add(url($uri), $now, $this->getPriority($uri), $this->getChangeFreq($uri));
    }
}

private function getPriority($uri)
{
    if ($uri === '/') return '1.0';
    if ($uri === 'pricelist') return '0.9';
    if (in_array($uri, ['faq', 'contact', 'airtime-data', 'cable-tv', 'electricity'])) return '0.8';
    if (in_array($uri, ['terms-of-service', 'privacy-policy', 'login', 'register'])) return '0.6';
    return '0.5';
}

private function getChangeFreq($uri)
{
    if ($uri === 'pricelist') return 'hourly';
    if (in_array($uri, ['airtime-data', 'cable-tv', 'electricity'])) return 'daily';
    if (in_array($uri, ['faq', 'contact', 'terms-of-service', 'privacy-policy'])) return 'monthly';
    return 'weekly';
}
}