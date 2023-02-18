import { test, expect, chromium, Page } from '@playwright/test';
import PlaywrightConfig from '../../playwright.config';
import { intervalRepeater } from '../../utils/Progress';
import { ZapClient, Mode, ContextType, Risk, HttpMessage } from '../../utils/ZapClient';
import { ECCUBE_ADMIN_ROUTE } from '../../config/default.config';

const zapClient = new ZapClient();

const url = `${PlaywrightConfig.use.baseURL}/${ECCUBE_ADMIN_ROUTE}/customer/new`;

test.describe.serial('会員管理 会員登録のテストを行います', () => {
  let page: Page;
  test.beforeAll(async () => {
    await zapClient.setMode(Mode.Protect);
    await zapClient.newSession('/zap/wrk/sessions/admin_customer_new', true);
    await zapClient.importContext(ContextType.Admin);

    if (!await zapClient.isForcedUserModeEnabled()) {
      await zapClient.setForcedUserModeEnabled();
      expect(await zapClient.isForcedUserModeEnabled()).toBeTruthy();
    }
    const browser = await chromium.launch();
    page = await browser.newPage();
    await page.goto(url);
  });

  test('会員管理 会員登録のページを表示します', async () => {
    await expect(page).toHaveTitle(/会員登録/);
  });

  test('タイトルを確認します', async () => {
    await page.textContent('.c-pageTitle__subTitle')
      .then(title => expect(title).toContain('会員管理'));
  });

  test.describe('テストを実行します[GET] @attack', () => {
    let scanId: number;
    test('アクティブスキャンを実行します', async () => {
      scanId = await zapClient.activeScanAsUser(url, 2, 55, false, null, 'GET');
      await intervalRepeater(async () => await zapClient.getActiveScanStatus(scanId), 5000, page);
    });

    test('結果を確認します', async () => {
      await zapClient.getAlerts(url, 0, 1, Risk.High)
        .then(alerts => expect(alerts).toEqual([]));
    });
  });

  test('ユーザ情報を入力します', async () => {
    await page.locator('id=admin_customer_name_name01').fill('山田');
    await page.locator('id=admin_customer_name_name02').fill('太郎');
    await page.locator('id=admin_customer_kana_kana01').fill('ヤマダ');
    await page.locator('id=admin_customer_kana_kana02').fill('タロウ');
    await page.locator('id=admin_customer_company_name').fill('イーシーキューブ');
    await page.locator('id=admin_customer_postal_code').fill('5300001');
    await page.locator('id=admin_customer_address_pref').selectOption('1');
    await page.locator('id=admin_customer_address_addr01').fill('大阪市北区梅田');
    await page.locator('id=admin_customer_address_addr02').fill('2-4-9');
    await page.locator('id=admin_customer_email').fill('taro_yamada@test.local');
    await page.locator('id=admin_customer_phone_number').fill('0001112222');
    await page.locator('id=admin_customer_password_first').fill('password123');
    await page.locator('id=admin_customer_password_second').fill('password123');
    await page.locator('id=admin_customer_sex_1').click();
    await page.locator('id=admin_customer_job').selectOption('3');
    await page.locator('id=admin_customer_birth').fill('1980-04-01');
    await page.locator('id=admin_customer_point').fill('10');
    await page.locator('id=admin_customer_note').fill('国語国語国語国語国語国語国語国語国語国語国語国語国語国語国語国語国語国語国語国語国語国語');
    await page.click('button >> text=登録');
  });

  let message: HttpMessage;
  test('HttpMessage を取得します', async () => {
    message = await zapClient.getLastMessage(url);
  });

  test.describe('テストを実行します[POST][入力→登録] @attack', () => {
    let scanId: number;
    test('アクティブスキャンを実行します', async () => {
      scanId = await zapClient.activeScan(url, false, true, null, 'POST', message.requestBody);
      await intervalRepeater(async () => await zapClient.getActiveScanStatus(scanId), 5000, page);
    });

    test('ユーザ登録の結果を確認します', async () => {
      await zapClient.getAlerts(url, 0, 1, Risk.High)
        .then(alerts => expect(alerts).toEqual([]));
    });
  });
});
