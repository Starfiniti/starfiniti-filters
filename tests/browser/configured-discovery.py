"""Real localhost browser coverage; run after configured-discovery-fixture.php setup."""
import argparse
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

parser = argparse.ArgumentParser()
parser.add_argument('--channel', default=None)
args = parser.parse_args()

print('Starting Playwright driver', flush=True)
with sync_playwright() as playwright:
    print('Starting isolated headless browser', flush=True)
    browser = playwright.chromium.launch(channel=args.channel, headless=True, timeout=15000)
    try:
        for width in [1280, 360, 320]:
            page = browser.new_page(viewport={'width': width, 'height': 900})
            errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.goto('http://127.0.0.1:8088/starfiniti-search-qualification/')
            page.wait_for_load_state('networkidle')
            root = page.locator('[data-starfiniti-discovery]')
            # Exercise a wide embedding host as well as the real narrow theme host.
            if width == 1280:
                root.evaluate('(root) => { document.body.replaceChildren(root); document.body.style.padding="32px"; root.style.maxWidth="none"; root.style.width="100%"; }')
            expect(root).to_have_count(1)
            expect(root.locator('[data-sfs-filter]')).to_have_count(4)
            expect(root.locator('[data-sfs-results]')).to_have_attribute('aria-busy', 'false')
            status = root.locator('[data-sfs-status]')
            panel = root.locator('[data-sfs-panel]')
            opener = root.locator('[data-sfs-open-filters]')
            if width == 1280:
                expect(root).to_have_attribute('data-compact', 'false')
                expect(panel).to_be_visible()
            def open_panel():
                if not panel.is_visible():
                    opener.click()
            def close_panel():
                if opener.is_visible() and panel.is_visible():
                    root.locator('[data-sfs-close-filters]').click()
            if width < 800:
                expect(panel).not_to_be_visible()
                assert root.locator('[data-sfs-results]').bounding_box()['y'] - root.bounding_box()['y'] < 400
                opener.click()
                expect(panel).to_be_visible()
                expect(root.locator('[data-sfs-close-filters]')).to_be_focused()
                # Native modal contains keyboard focus and restores its opener.
                page.keyboard.press('Shift+Tab')
                assert panel.evaluate('(panel) => panel.contains(document.activeElement)')
                page.keyboard.press('Escape')
                expect(panel).not_to_be_visible()
                expect(opener).to_be_focused()
            open_panel()
            # Reconnaissance uses the rendered controls, not a mocked response or index.
            colors = root.locator('[data-sfs-filter="color"] input')
            values = colors.evaluate_all('(inputs) => inputs.map(input => input.value)')
            assert 'Red' in values and 'Blue' in values, values
            assert 'Secret' not in values, 'Hidden product leaked into the public facet'
            red = root.locator('[data-sfs-filter="color"] input[value="Red"]')
            red.focus()
            page.keyboard.press('Space')
            expect(status).to_have_text('1 products found.')
            expect(red).to_be_focused()
            blue = root.locator('[data-sfs-filter="color"] input[value="Blue"]')
            expect(blue).to_be_visible()
            blue.check()
            expect(status).to_have_text('2 products found.')
            expect(red).to_be_checked()
            blue.uncheck()
            expect(status).to_have_text('1 products found.')
            expect(root.locator('[data-sfs-results]')).to_contain_text('SFS Browser Fixture Red')
            expect(root.locator('[data-sfs-results] article')).to_have_count(1)
            assert 'color=Red' in page.url
            page.reload(wait_until='networkidle')
            if width == 1280:
                root.evaluate('(root) => { document.body.replaceChildren(root); document.body.style.padding="32px"; root.style.maxWidth="none"; root.style.width="100%"; }')
            open_panel()
            expect(red).to_be_checked()
            expect(root.locator('[data-sfs-results] article')).to_have_count(1)
            minimum = root.locator('[data-sfs-min]')
            maximum = root.locator('[data-sfs-max]')
            minimum.fill('10.01')
            maximum.fill('10.01')
            maximum.press('Tab')
            page.wait_for_load_state('networkidle')
            expect(root.locator('[data-sfs-results] article')).to_have_count(1)
            minimum.fill('10.02')
            minimum.press('Tab')
            expect(status).to_contain_text('Check the price range')
            expect(minimum).to_have_attribute('aria-invalid', 'true')
            assert minimum.get_attribute('aria-describedby')
            expect(root.locator('[data-sfs-results] article')).to_have_count(0)
            maximum.fill('10.03')
            maximum.press('Tab')
            expect(status).to_contain_text('0 products found')
            expect(red).to_be_checked()  # Zero-count selections remain removable.
            close_panel()
            root.locator('[data-sfs-clear]').click()
            expect(red).not_to_be_checked()
            expect(root.locator('[data-sfs-results]')).to_contain_text('SFS Browser Fixture Blue')
            page.go_back(wait_until='networkidle')
            if width == 1280:
                root.evaluate('(root) => { document.body.replaceChildren(root); document.body.style.padding="32px"; root.style.maxWidth="none"; root.style.width="100%"; }')
                expect(root).to_have_attribute('data-compact', 'false')
            expect(red).to_be_checked()
            expect(minimum).to_have_value('10.02')
            expect(maximum).to_have_value('10.03')
            root.locator('[data-sfs-clear]').click()
            page.wait_for_load_state('networkidle')
            open_panel()
            root.locator('[data-sfs-filter="stock"] input[value="instock"]').check()
            page.wait_for_load_state('networkidle')
            assert 'stock-status=instock' in page.url
            root.locator('[data-sfs-filter="featured"] select').select_option('0')
            expect(root.locator('[data-sfs-filter="featured"] select')).to_contain_text('Not featured')
            expect(root.locator('[data-sfs-stock]')).to_have_count(0)
            page.wait_for_load_state('networkidle')
            assert 'featured=0' in page.url
            close_panel()
            query = root.locator('input[name="s"]')
            # A quoted phrase isolates our products from other fixture descriptions.
            query.fill('"SFS Browser Fixture"')
            root.locator('button[type="submit"]').click()
            expect(status).to_have_text('2 products found.')
            root.locator('[data-sfs-clear]').click()
            expect(query).to_have_value('"SFS Browser Fixture"')
            expect(status).to_have_text('2 products found.')
            open_panel()
            red.check()
            expect(status).to_have_text('1 products found.')
            close_panel()
            root.locator('[data-sfs-chips] button').click()
            expect(status).to_have_text('2 products found.')
            expect(root.locator('[data-sfs-chips] button')).to_have_count(0)
            # Keep evidence of the actual current renderer, not a drawn mockup.
            if width == 1280:
                expect(root).to_have_attribute('data-compact', 'false')
            evidence = Path(__file__).resolve().parents[2] / '.cache' / 'ui-fix-20260907'
            evidence.mkdir(parents=True, exist_ok=True)
            root.screenshot(path=str(evidence / f'discovery-{width}.png'))
            if width < 800:
                open_panel()
                root.locator('.sfs-discovery__filter-groups').evaluate('(group) => { group.scrollTop = 0; }')
                panel.screenshot(path=str(evidence / f'filters-{width}.png'))
                assert panel.evaluate('(panel) => panel.scrollWidth <= panel.clientWidth')
                close_panel()
            # A server error must offer recovery without losing the query.
            page.route('**/wp-json/starfiniti-search/v1/search', lambda route: route.fulfill(status=503, body='{}', content_type='application/json'))
            root.locator('button[type="submit"]').click()
            expect(root.locator('[data-sfs-retry]')).to_be_visible()
            expect(query).to_have_value('"SFS Browser Fixture"')
            page.unroute('**/wp-json/starfiniti-search/v1/search')
            root.locator('[data-sfs-retry]').click()
            expect(status).to_have_text('2 products found.')
            assert not errors, errors
            assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), 'Horizontal overflow'
            page.close()
            print(f'PASS configured discovery at {width}px: alternative counts, keyboard focus, responsive panel, price errors, chips, clear semantics, history, visibility and retry')
    finally:
        browser.close()
