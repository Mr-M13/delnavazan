#!/usr/bin/env node
/**
 * Delnavazan LiveKit classroom browser acceptance.
 *
 * This is a manual/staging browser check, not part of the PHP source contract suite. It runs against
 * a disposable or staging WordPress with the Delnavazan theme and a synthetic Teacher/Student
 * lesson inside the canonical joining window.
 *
 * Install Playwright once (no browser download is needed when Chrome is installed):
 *   npm install playwright
 *
 * Run:
 *   DZN_BROWSER_STUDENT_USER=... DZN_BROWSER_STUDENT_PASS=... \
 *   DZN_BROWSER_TEACHER_USER=... DZN_BROWSER_TEACHER_PASS=... \
 *   DZN_BROWSER_ADMIN_USER=... DZN_BROWSER_ADMIN_PASS=... \
 *   DZN_BROWSER_EXPECT=unavailable|connected \
 *   node platform/runtime/browser/livekit-classroom-browser-test.js
 *
 * `unavailable` proves the explicit Persian fallback when LiveKit credentials are absent.
 * `connected` proves a real room connection with fake camera/microphone devices.
 */
const { chromium } = require('playwright');
const path = require('path');

const base = (process.env.DZN_BROWSER_BASE || 'http://127.0.0.1:18088').replace(/\/$/, '');
const expect = process.env.DZN_BROWSER_EXPECT || 'unavailable';
const out = process.env.DZN_BROWSER_OUTPUT || '/tmp/dzn-livekit-browser';
const credentials = {
  student: [process.env.DZN_BROWSER_STUDENT_USER, process.env.DZN_BROWSER_STUDENT_PASS],
  teacher: [process.env.DZN_BROWSER_TEACHER_USER, process.env.DZN_BROWSER_TEACHER_PASS],
  admin: [process.env.DZN_BROWSER_ADMIN_USER, process.env.DZN_BROWSER_ADMIN_PASS],
};
const failures = [];
const check = (ok, label) => {
  if (ok) { console.log('PASS ' + label); return; }
  failures.push(label);
  console.error('FAIL ' + label);
};
const required = (name) => {
  if (!credentials[name][0] || !credentials[name][1]) throw new Error('Missing browser credentials for ' + name);
  return credentials[name];
};

async function login(context, role) {
  const [user, pass] = required(role);
  const page = await context.newPage();
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('#wp-submit')]);
  return page;
}

(async () => {
  const browser = await chromium.launch({
    channel: process.env.DZN_BROWSER_CHANNEL || 'chrome',
    headless: true,
    args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'],
  });
  const errors = [];

  try {
    const studentContext = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR', permissions: ['camera', 'microphone'] });
    const student = await login(studentContext, 'student');
    student.on('pageerror', (error) => errors.push('student:' + error.message));
    student.on('console', (message) => { if (message.type() === 'error') errors.push('student-console:' + message.text()); });
    await student.goto(base + '/student-portal/', { waitUntil: 'domcontentloaded' });
    const portal = await student.content();
    check((await student.getAttribute('html', 'dir')) === 'rtl', 'student portal renders RTL');
    check(portal.includes('dzn_student_livekit_class'), 'student portal offers the LiveKit launch boundary');
    check(!portal.includes('meet.google.com'), 'portal does not expose a Google Meet URL');
    check(await student.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'desktop portal has no horizontal overflow');
    await student.screenshot({ path: path.join(out, 'student-portal-desktop.png'), fullPage: true });

    await student.setViewportSize({ width: 390, height: 844 });
    await student.reload({ waitUntil: 'domcontentloaded' });
    check(await student.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'mobile portal has no horizontal overflow');
    await student.screenshot({ path: path.join(out, 'student-portal-mobile.png'), fullPage: true });

    await student.setViewportSize({ width: 1280, height: 900 });
    await student.reload({ waitUntil: 'domcontentloaded' });
    const [classroom] = await Promise.all([
      studentContext.waitForEvent('page'),
      student.click('form[action*="dzn_student_livekit_class"] button'),
    ]);
    classroom.on('pageerror', (error) => errors.push('classroom:' + error.message));
    classroom.on('console', (message) => { if (message.type() === 'error') errors.push('classroom-console:' + message.text()); });
    await classroom.waitForLoadState('domcontentloaded');
    await classroom.waitForTimeout(expect === 'connected' ? 8000 : 4000);
    const html = await classroom.content();
    check(classroom.url().includes('/music-classroom/'), 'launch opens the classroom route');
    check(!classroom.url().includes('ticket='), 'signed launch ticket is removed from the visible URL');
    check(html.includes('کلاس موسیقی') && html.includes('دلنوازان'), 'classroom carries Delnavazan branding');
    check((await classroom.locator('.dzn-classroom-watermark').count()) === 2, 'both video tiles carry the watermark');
    check(await classroom.locator('#dzn-classroom-mic').count() === 1 && await classroom.locator('#dzn-classroom-camera').count() === 1, 'microphone and camera controls render');
    check(await classroom.locator('#dzn-classroom-music').count() === 1, 'Music Mode renders');
    check(await classroom.locator('#dzn-classroom-meet').count() === 1, 'explicit Meet fallback form renders');
    if (expect === 'connected') {
      check((await classroom.getAttribute('#dzn-classroom-connection', 'data-state')) === 'connected', 'LiveKit classroom reports connected');
      check(await classroom.locator('#dzn-classroom-participants').innerText().then((text) => text.includes('۱') || text.includes('۲')), 'participant count is bounded to one or two');
    } else {
      const visible = await classroom.locator('#dzn-classroom-alert').isVisible();
      const text = visible ? await classroom.locator('#dzn-classroom-alert').innerText() : '';
      check(visible && text.includes('سرویس کلاس زنده'), 'missing LiveKit credentials show a Persian fallback message');
      check((await classroom.getAttribute('#dzn-classroom-connection', 'data-state')) === 'error', 'connection state is error when LiveKit is unavailable');
    }
    check(await classroom.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'desktop classroom has no horizontal overflow');
    await classroom.screenshot({ path: path.join(out, 'classroom-desktop.png'), fullPage: true });
    await classroom.setViewportSize({ width: 768, height: 1024 });
    check(await classroom.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'iPad-sized classroom has no horizontal overflow');
    await classroom.screenshot({ path: path.join(out, 'classroom-tablet.png'), fullPage: true });

    const teacherContext = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
    const teacher = await login(teacherContext, 'teacher');
    await teacher.goto(base + '/teacher-portal/?teacher-view=classes', { waitUntil: 'domcontentloaded' });
    const teacherHtml = await teacher.content();
    check(!teacherHtml.includes('Fatal error') && !teacherHtml.includes('There has been a critical error'), 'teacher portal has no fatal error');
    check(teacherHtml.includes('ورود به کلاس') || teacherHtml.includes('کلاسی برای امروز ثبت نشده'), 'teacher portal renders class state');

    if (credentials.admin[0] && credentials.admin[1]) {
      const adminContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      const admin = await login(adminContext, 'admin');
      await admin.goto(base + '/student-portal/', { waitUntil: 'domcontentloaded' });
      const adminHtml = await admin.content();
      check(!adminHtml.includes('dzn_student_livekit_class'), 'unlinked administrator has no student classroom action');
      check(!adminHtml.includes('name="ticket"'), 'no launch ticket is rendered into portal HTML');
    }

    check(errors.length === 0, 'no browser console or page errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  } finally {
    await browser.close();
  }
  if (failures.length) {
    console.error('BROWSER FAIL: ' + failures.join('; '));
    process.exit(1);
  }
  console.log('BROWSER PASS');
})().catch((error) => { console.error(error.stack || error); process.exit(1); });
