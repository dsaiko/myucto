<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Vyúčtování s výsledkem k vyplacení v tiskových výstupech: PDF a e-mail místo výzvy
 * k úhradě hlásí „K vrácení" s kladnou částkou a bez platebních údajů, ISDOC projde XSD.
 */
#[Group('integration')]
final class RefundInvoiceOutputsTest extends StockTestCase
{
    private function refundInvoice(string $paymentMethod, string $type = 'invoice', string $language = 'cs'): array
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $this->db->pdo()->prepare(
            "UPDATE currencies SET account_number = '1000000005', bank_code = '0100' WHERE id = ?"
        )->execute([$this->currencyIdFor($sid)]);
        $repo = $this->container->get(InvoiceRepository::class);
        $id = $repo->createDraft([
            'invoice_type' => $type,
            'client_id' => $this->client($sid),
            'currency_id' => $this->currencyIdFor($sid),
            'issue_date' => '2099-06-10', 'tax_date' => '2099-06-10', 'due_date' => '2099-06-24',
            'payment_method' => $paymentMethod, 'prices_include_vat' => false, 'language' => $language,
        ], $this->userId);
        $repo->replaceItems($id, [
            ['description' => 'Syntetické zboží', 'quantity' => 1, 'unit_price_without_vat' => 1000.00,
             'vat_rate_id' => $this->vatRateId, 'vat_classification_code' => '1'],
            ['description' => 'Syntetická vratka', 'quantity' => 1, 'unit_price_without_vat' => -1502.15,
             'vat_rate_id' => $this->vatRateId, 'vat_classification_code' => '1'],
        ]);
        $this->container->get(InvoiceCalculator::class)->recompute($id);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = '2099001' WHERE id = ?")
            ->execute([$id]);

        return $repo->find($id);
    }

    /** Běžná (ne-refundová) faktura s kladným zůstatkem, pro testy popisku "K úhradě"/"Uhrazeno". */
    private function positiveInvoice(string $language = 'cs'): array
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $this->db->pdo()->prepare(
            "UPDATE currencies SET account_number = '1000000005', bank_code = '0100' WHERE id = ?"
        )->execute([$this->currencyIdFor($sid)]);
        $repo = $this->container->get(InvoiceRepository::class);
        $id = $repo->createDraft([
            'invoice_type' => 'invoice',
            'client_id' => $this->client($sid),
            'currency_id' => $this->currencyIdFor($sid),
            'issue_date' => '2099-06-10', 'tax_date' => '2099-06-10', 'due_date' => '2099-06-24',
            'payment_method' => 'bank_transfer', 'prices_include_vat' => false, 'language' => $language,
        ], $this->userId);
        $repo->replaceItems($id, [
            ['description' => 'Syntetické zboží', 'quantity' => 1, 'unit_price_without_vat' => 500.00,
             'vat_rate_id' => $this->vatRateId, 'vat_classification_code' => '1'],
        ]);
        $this->container->get(InvoiceCalculator::class)->recompute($id);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = '2099002' WHERE id = ?")
            ->execute([$id]);

        return $repo->find($id);
    }

    public function testPaidNonRefundInvoiceEmailUsesAmountDueLabelAcrossLocales(): void
    {
        // Regrese pro commit 3c4d305c3 — dřív plně zaplacená faktura ukazovala
        // "Uhrazeno: 0,00 Kč"/"Paid: 0.00", místo jednotného "K úhradě"/"Amount due".
        $inv = $this->positiveInvoice();
        $inv['status'] = 'paid';
        $inv['paid_total'] = $inv['amount_to_pay'];

        // Zakázaný řetězec a přesná kontrola řádku s částkou musí sedět per formát —
        // '>Paid<' dává smysl jen v HTML, txt má "Paid:" (nález fixpoint review, 3. kolo).
        $cases = [
            'cs' => ['html' => ['Uhrazeno', '~K úhradě</td>\s*<td[^>]*>\s*0,00 CZK\s*✓~u'], 'txt' => ['Uhrazeno:', '~K úhradě:\s+0,00 CZK ✓~u']],
            'en' => ['html' => ['>Paid<', '~Amount due</td>\s*<td[^>]*>\s*0\.00 CZK\s*✓~u'], 'txt' => ['Paid:', '~Amount due:\s+0\.00 CZK ✓~u']],
        ];
        foreach ($cases as $locale => $formats) {
            $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->build($inv, true, $locale);
            self::assertTrue($vars['is_paid']);
            self::assertSame(0.0, $vars['amount_to_pay']);

            foreach ($formats as $format => [$forbiddenLabel, $amountLineRegex]) {
                $body = $this->renderEmail("invoice_send.{$locale}.{$format}.twig", $vars);
                self::assertStringNotContainsString($forbiddenLabel, $body);
                self::assertMatchesRegularExpression($amountLineRegex, $body);
            }
        }
    }

    public function testPaidInvoiceWithToleranceResidualShowsZeroNotResidual(): void
    {
        // InvoicePaymentService::TOLERANCE (0.05) nechává fakturu "paid" i s drobným
        // zůstatkem (zaokrouhlení platby) — e-mail nesmí ukázat kladnou částku k úhradě
        // vedle textu "Faktura již byla uhrazena. Neplaťte prosím znovu."
        $inv = $this->positiveInvoice();
        $inv['status'] = 'paid';
        $inv['paid_total'] = round((float) $inv['amount_to_pay'] - 0.03, 2);

        $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->build($inv, true, 'cs');
        self::assertSame(0.0, $vars['amount_to_pay']);

        $body = $this->renderEmail('invoice_send.cs.html.twig', $vars);
        self::assertStringContainsString('K úhradě', $body);
        self::assertStringContainsString('0,00 CZK', $body);
        self::assertStringNotContainsString('0,03', $body);
    }

    public function testPaidInvoiceWithBalanceAboveToleranceIsNotTreatedAsPaid(): void
    {
        // Admin může dovybavit položky už zaplacené faktury (DocumentLockService "PF
        // status 'paid' NEZAMYKÁ") — status/paid_total zůstanou nedotčené a SendEmailAction
        // pošle e-mail i tak. Zůstatek nad InvoicePaymentService::TOLERANCE (0,05 Kč) se
        // nesmí skrýt na 0 ani se nesmí tvářit jako uhrazeno — jinak appka zatají klientovi
        // skutečný doplatek a ještě potlačí QR platbu (2. kolo fixpoint review).
        $inv = $this->positiveInvoice();
        $inv['status'] = 'paid';
        $inv['paid_total'] = round((float) $inv['amount_to_pay'] - 100.0, 2);

        $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->build($inv, true, 'cs');
        self::assertFalse($vars['is_paid']);
        self::assertEqualsWithDelta(100.0, $vars['amount_to_pay'], 0.001);
        self::assertNotNull($this->container->get(InvoiceEmailVarsBuilder::class)->paymentQrDataUri($inv));

        $body = $this->renderEmail('invoice_send.cs.html.twig', $vars);
        self::assertStringContainsString('100,00 CZK', $body);
        self::assertStringNotContainsString('Faktura již byla uhrazena', $body);
        self::assertStringNotContainsString('✓', $body);
    }

    public function testLegacyPaidInvoiceWithoutRecordedPaymentShowsZeroDue(): void
    {
        // Konvence z InvoiceRepository::remainingSql() (doklady "paid" z dob před #89,
        // bez evidence plateb — paid_total 0) musí platit i v e-mailu: plná částka k
        // úhradě vedle "Faktura již byla uhrazena" by byla vyloženě protimluv.
        $inv = $this->positiveInvoice();
        $inv['status'] = 'paid';
        $inv['paid_total'] = 0.0;

        $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->build($inv, true, 'cs');
        self::assertTrue($vars['is_paid']);
        self::assertSame(0.0, $vars['amount_to_pay']);
        self::assertNull($this->container->get(InvoiceEmailVarsBuilder::class)->paymentQrDataUri($inv));

        $body = $this->renderEmail('invoice_send.cs.html.twig', $vars);
        self::assertStringContainsString('Faktura již byla uhrazena', $body);
        self::assertMatchesRegularExpression('~K úhradě</td>\s*<td[^>]*>\s*0,00 CZK~u', $body);
    }

    public function testSmallOverpaymentWithinToleranceShowsZeroNotNegative(): void
    {
        // Tolerance musí fungovat na obě strany zůstatku — drobný přeplatek (zaokrouhlení
        // platby) nesmí v e-mailu vyjít jako záporná částka k úhradě.
        $inv = $this->positiveInvoice();
        $inv['status'] = 'paid';
        $inv['paid_total'] = round((float) $inv['amount_to_pay'] + 0.03, 2);

        $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->build($inv, true, 'cs');
        self::assertTrue($vars['is_paid']);
        self::assertSame(0.0, $vars['amount_to_pay']);
    }

    public function testOverpaymentAboveToleranceShowsZeroNotNegative(): void
    {
        // Přeplatek nad InvoicePaymentService::TOLERANCE (klient reálně NIC nedluží) nesmí
        // v e-mailu vyjít jako záporná částka k úhradě bez "uhrazeno" sdělení — vratka
        // přeplatku je samostatné téma, mimo rozsah téhle faktury-k-úhradě sekce.
        $inv = $this->positiveInvoice();
        $inv['status'] = 'paid';
        $inv['paid_total'] = round((float) $inv['amount_to_pay'] + 100.0, 2);

        $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->build($inv, true, 'cs');
        self::assertTrue($vars['is_paid']);
        self::assertSame(0.0, $vars['amount_to_pay']);

        $body = $this->renderEmail('invoice_send.cs.html.twig', $vars);
        self::assertStringContainsString('Faktura již byla uhrazena', $body);
        self::assertStringNotContainsString('-100', $body);
    }

    public function testReminderKeepsFullBalanceForUnpaidPartialPayment(): void
    {
        // buildReminder() sdílí remainingAmount() s build() — upomínka po částečné úhradě
        // (#89) musí chtít celý zbytek dluhu, ne 0.
        $inv = $this->positiveInvoice();
        $inv['paid_total'] = 40.0;

        $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->buildReminder($inv, 5, 'cs');
        self::assertFalse($vars['is_paid']);
        self::assertEqualsWithDelta((float) $inv['amount_to_pay'] - 40.0, $vars['amount_to_pay'], 0.001);
    }

    public function testReminderZeroesToleranceResidualWhenPaid(): void
    {
        $inv = $this->positiveInvoice();
        $inv['status'] = 'paid';
        $inv['paid_total'] = round((float) $inv['amount_to_pay'] - 0.03, 2);

        $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->buildReminder($inv, 1, 'cs');
        self::assertSame(0.0, $vars['amount_to_pay']);
    }

    public function testTransferRefundShowsAmountToRefundWithoutPaymentDetails(): void
    {
        $inv = $this->refundInvoice('bank_transfer');
        self::assertEqualsWithDelta(-607.60, (float) $inv['amount_to_pay'], 0.001);

        $html = $this->container->get(InvoicePdfRenderer::class)->renderHtml($inv);
        self::assertMatchesRegularExpression('~K vrácení</td>\s*<td[^>]*>607,60 CZK~u', $html);
        self::assertStringNotContainsString('K úhradě', $html);
        self::assertStringContainsString('Částku vám vrátíme převodem pod variabilním symbolem', $html);
        self::assertStringContainsString('2099001', $html);
        self::assertStringNotContainsString('alt="QR"', $html);
        self::assertStringNotContainsString('Bankovní spojení', $html);
        self::assertStringNotContainsString('1000000005', $html);
    }

    public function testCashRefundIsRoundedAndSaysPaidOutWhenPaid(): void
    {
        $inv = $this->refundInvoice('cash');
        self::assertEqualsWithDelta(-608.00, (float) $inv['amount_to_pay'], 0.001);
        $renderer = $this->container->get(InvoicePdfRenderer::class);

        $html = $renderer->renderHtml($inv);
        self::assertStringContainsString('Částku vám vyplatíme v hotovosti.', $html);
        self::assertStringContainsString('608,00', $html);

        $inv['status'] = 'paid';
        $inv['paid_at'] = '2099-06-10';
        $paid = $renderer->renderHtml($inv);
        self::assertStringContainsString('Vyplaceno v hotovosti.', $paid);
        self::assertStringNotContainsString('Neplaťte prosím znovu', $paid);
    }

    public function testEnglishPdfAndEmailUseRefundTexts(): void
    {
        $inv = $this->refundInvoice('bank_transfer', 'invoice', 'en');
        $html = $this->container->get(InvoicePdfRenderer::class)->renderHtml($inv);
        self::assertStringContainsString('Amount to be refunded', $html);
        self::assertStringContainsString('We will refund the amount by bank transfer under variable symbol', $html);

        $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->build($inv, true, 'en');
        self::assertEqualsWithDelta(607.60, $vars['refund_amount'], 0.001);
        self::assertNull($vars['qr_data_uri']);
        foreach (['en', 'cs'] as $locale) {
            foreach (['html', 'txt'] as $format) {
                $body = $this->renderEmail("invoice_send.{$locale}.{$format}.twig", $vars);
                self::assertStringContainsString($locale === 'en' ? 'o be refunded' : 'K vrácení', $body);
                self::assertStringContainsString('607', $body);
                self::assertStringNotContainsString('-607', $body);
                self::assertStringContainsString($locale === 'en' ? 'under variable symbol' : 'pod variabilním symbolem', $body);
            }
        }
    }

    public function testCreditNoteAndPositiveInvoiceKeepTheirTexts(): void
    {
        $credit = $this->refundInvoice('bank_transfer', 'credit_note');
        $html = $this->container->get(InvoicePdfRenderer::class)->renderHtml($credit);
        self::assertStringNotContainsString('K vrácení', $html);
        self::assertNull($this->container->get(InvoiceEmailVarsBuilder::class)->build($credit, true, 'cs')['refund_amount']);

        self::assertNull(InvoicePdfRenderer::refundInvoiceAmount(['invoice_type' => 'invoice', 'amount_to_pay' => 608.0]));
        self::assertNull(InvoicePdfRenderer::refundInvoiceAmount(
            ['invoice_type' => 'invoice', 'amount_to_pay' => -100.0, 'parent_invoice_id' => 5],
        ));
    }

    public function testIsdocWithNegativePayableAmountValidates(): void
    {
        $inv = $this->refundInvoice('bank_transfer');
        $xml = $this->container->get(IsdocExporter::class)->buildXml($inv);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        self::assertTrue($dom->schemaValidate(dirname(__DIR__, 3) . '/xsd/isdoc-invoice-6.0.2.xsd'));
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        self::assertSame('1', $xp->evaluate('string(//i:DocumentType)'));
        self::assertEqualsWithDelta(-607.60, (float) $xp->evaluate('string(//i:LegalMonetaryTotal/i:PayableAmount)'), 0.001);
    }

    /** @param array<string,mixed> $vars */
    private function renderEmail(string $template, array $vars): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email'), [
            'autoescape' => 'html', 'strict_variables' => false,
        ]);

        return $twig->render($template, $vars + ['accent' => '#3B2D83', 'accent_soft' => '#F1EEFA']);
    }
}
