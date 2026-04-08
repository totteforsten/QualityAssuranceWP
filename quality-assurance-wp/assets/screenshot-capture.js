#!/usr/bin/env node
/**
 * Quality Assurance WP - Screenshot Capture Script
 *
 * Usage: node screenshot-capture.js <url> <output_path> <width> <height>
 *
 * Requires: npm install puppeteer
 */

const puppeteer = require('puppeteer');

const args = process.argv.slice(2);

if (args.length < 4) {
    console.error('Usage: node screenshot-capture.js <url> <output_path> <width> <height>');
    process.exit(1);
}

const [url, outputPath, width, height] = args;
const viewportWidth = parseInt(width, 10);
const viewportHeight = parseInt(height, 10);

if (!url || !outputPath || isNaN(viewportWidth) || isNaN(viewportHeight)) {
    console.error('Invalid arguments.');
    process.exit(1);
}

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-web-security',
                '--disable-features=IsolateOrigins',
            ],
        });

        const page = await browser.newPage();

        // Set viewport.
        await page.setViewport({
            width: viewportWidth,
            height: viewportHeight,
            deviceScaleFactor: 1,
        });

        // Set a reasonable user agent.
        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        // Navigate and wait for content to load.
        await page.goto(url, {
            waitUntil: 'networkidle2',
            timeout: 60000,
        });

        // Wait for any lazy-loaded images and animations.
        await page.evaluate(async () => {
            // Scroll down to trigger lazy loading.
            await new Promise((resolve) => {
                let totalHeight = 0;
                const distance = 200;
                const timer = setInterval(() => {
                    const scrollHeight = document.body.scrollHeight;
                    window.scrollBy(0, distance);
                    totalHeight += distance;
                    if (totalHeight >= scrollHeight) {
                        clearInterval(timer);
                        resolve();
                    }
                }, 50);
            });

            // Scroll back to top.
            window.scrollTo(0, 0);

            // Wait for images to finish loading.
            await Promise.all(
                Array.from(document.images)
                    .filter((img) => !img.complete)
                    .map(
                        (img) =>
                            new Promise((resolve) => {
                                img.onload = img.onerror = resolve;
                            })
                    )
            );
        });

        // Brief pause for CSS animations/transitions to settle.
        await new Promise((resolve) => setTimeout(resolve, 1000));

        // Take full-page screenshot.
        await page.screenshot({
            path: outputPath,
            fullPage: true,
            type: 'png',
        });

        console.log('Screenshot saved: ' + outputPath);
        process.exit(0);

    } catch (error) {
        console.error('Screenshot capture failed:', error.message);
        process.exit(1);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();
