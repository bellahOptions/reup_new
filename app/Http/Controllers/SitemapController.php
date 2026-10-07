<?php

namespace App\Http\Controllers;

use Carbon\Carbon;

class SitemapController extends Controller
{


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
        
        return <<<LLMTXT
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
LLMTXT;
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

}
