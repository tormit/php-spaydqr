<?php

declare(strict_types=1);

namespace PetrKnap\SpaydQr;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Builder\BuilderInterface;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Sunfox\Spayd\Spayd;

/**
 * @todo make it final
 * @todo create SpaydBuilder
 */
class SpaydQr implements SpaydQrInterface
{
    /** @internal */
    protected function __construct(
        /** @internal */
        protected Spayd $spayd,
        /** @internal */
        protected BuilderInterface $qrCodeBuilder,
        string $iban,
        Money $money
    ) {
        $spayd
            ->add(self::SPAYD_IBAN, $iban)
            ->add(self::SPAYD_AMOUNT, $this->getAmount($money))
            ->add(self::SPAYD_CURRENCY, $money->getCurrency()->getCode());
    }

    public static function create(string $iban, Money $money, QrCodeWriter $writer = QrCodeWriter::Png): self
    {
        return new self(
            new Spayd(),
            Builder::create()
                   ->writer($writer->endroid())
                   ->encoding(new Encoding('UTF-8')),
            $iban,
            $money
        );
    }

    public function setVariableSymbol(int $variableSymbol): self
    {
        $this->spayd->add(self::SPAYD_VARIABLE_SYMBOL, (string)$variableSymbol);

        return $this;
    }

    public function setIban(string $iban): self
    {
        $this->spayd->add(self::SPAYD_IBAN, \PetrKnap\SpaydQr\Util::sanitizeSpaydValue($iban, 46));
        return $this;
    }

    /**
     * @param array<int, string> $altAccounts
     */
    public function setAltAccount(array $altAccounts): self
    {
        $altAccountsJoined = implode(',', array_splice($altAccounts, 0, 2));
        if (mb_strlen($altAccountsJoined) > 93) {
            // Take only one
            $altAccountsJoined = implode(',', array_splice($altAccounts, 0, 1));
        }
        $this->spayd->add(self::SPAYD_ALT_ACCOUNT, $altAccountsJoined);
        return $this;
    }

    public function setReference(string $reference): self
    {
        $this->spayd->add(self::SPAYD_REFERENCE, \PetrKnap\SpaydQr\Util::sanitizeSpaydValue($reference, 16));
        return $this;
    }

    public function setRecipientName(string $recipientName): self
    {
        $this->spayd->add(self::SPAYD_RECIPIENT_NAME, \PetrKnap\SpaydQr\Util::sanitizeSpaydValue($recipientName, 35, true));
        return $this;
    }

    public function setDueDate(string $dueDate): self
    {
        $this->spayd->add(self::SPAYD_DUE_DATE, \PetrKnap\SpaydQr\Util::sanitizeSpaydValue($dueDate, 8));
        return $this;
    }

    public function setPaymentType(string $paymentType): self
    {
        $this->spayd->add(self::SPAYD_PAYMENT_TYPE, \PetrKnap\SpaydQr\Util::sanitizeSpaydValue($paymentType, 3, true));
        return $this;
    }

    public function setMessage(string $message): self
    {
        $this->spayd->add(self::SPAYD_MESSAGE, \PetrKnap\SpaydQr\Util::sanitizeSpaydValue($message, 60, true));
        return $this;
    }

    public function setNotificationType(string $notificationType): self
    {
        $this->spayd->add(self::SPAYD_NOTIFICATION_TYPE, \PetrKnap\SpaydQr\Util::sanitizeSpaydValue($notificationType, 1));
        return $this;
    }

    public function setNotification(string $notification): self
    {
        $this->spayd->add(self::SPAYD_NOTIFICATION, \PetrKnap\SpaydQr\Util::sanitizeSpaydValue($notification, 320));
        return $this;
    }

    public function setInvoice(
        string $id,
        \DateTimeInterface $issueDate,
        int $sellerIdentificationNumber,
        ?string $sellerVatIdentificationNumber,
        ?int $buyerIdentificationNumber,
        ?string $buyerVatIdentificationNumber,
        ?string $description
    ): self {
        $normalize = function (string $input): string {
            return str_replace(
                ['*', '%2A', '%2a'],
                ['', '', ''],
                $input
            );
        };

        $invoice = [
            self::SPAYD_INVOICE_FORMAT,
            self::SPAYD_INVOICE_VERSION,
            self::SPAYD_INVOICE_ID . ':' . $normalize($id),
            self::SPAYD_INVOICE_ISSUE_DATE . ':' . $issueDate->format('Ymd'),
            self::SPAYD_INVOICE_SELLER_IDENTIFICATION_NUMBER . ':' . $sellerIdentificationNumber,
            $sellerVatIdentificationNumber ? self::SPAYD_INVOICE_SELLER_VAT_IDENTIFICATION_NUMBER . ':' . $normalize($sellerVatIdentificationNumber)
                : null,
            $buyerIdentificationNumber ? self::SPAYD_INVOICE_BUYER_IDENTIFICATION_NUMBER . ':' . $buyerIdentificationNumber : null,
            $buyerVatIdentificationNumber ? self::SPAYD_INVOICE_BUYER_VAT_IDENTIFICATION_NUMBER . ':' . $normalize($buyerVatIdentificationNumber)
                : null,
            $description ? self::SPAYD_INVOICE_MESSAGE . ':' . $normalize($description) : null,
        ];

        $this->spayd->add(self::SPAYD_INVOICE, implode('%2A', array_filter($invoice)));

        return $this;
    }

    public function setWriter(QrCodeWriter $writer): self
    {
        $this->qrCodeBuilder->writer($writer->endroid());

        return $this;
    }

    public function getContentType(): string
    {
        return $this->buildQrCode(null, null)->getMimeType();
    }

    public function getContent(int $size = self::QR_SIZE, int $margin = self::QR_MARGIN): string
    {
        return $this->buildQrCode($size, $margin)->getString();
    }

    public function getDataUri(int $size = self::QR_SIZE, int $margin = self::QR_MARGIN): string
    {
        return $this->buildQrCode($size, $margin)->getDataUri();
    }

    public function writeFile(string $path, int $size = self::QR_SIZE, int $margin = self::QR_MARGIN): void
    {
        $this->buildQrCode($size, $margin)->saveToFile($path);
    }

    public function getSpayd(): Spayd
    {
        return $this->spayd;
    }

    public function getQrCodeBuilder(): BuilderInterface
    {
        return $this->qrCodeBuilder;
    }

    /** @internal */
    protected function buildQrCode(?int $size, ?int $margin): ResultInterface
    {
        $this->qrCodeBuilder->data($this->spayd->generate());

        if ($size !== null) {
            $this->qrCodeBuilder->size($size);
        }

        if ($margin !== null) {
            $this->qrCodeBuilder->margin($margin);
        }

        return $this->qrCodeBuilder->build();
    }

    private function getAmount(Money $money): string
    {
        $currencies = new ISOCurrencies();
        $moneyFormatter = new DecimalMoneyFormatter($currencies);

        return $moneyFormatter->format($money);
    }
}
