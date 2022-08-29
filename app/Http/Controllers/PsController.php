<?php

namespace App\Http\Controllers;

use Carbon\Carbon;

/**
 * Constants
 */
define('PERCENT', 100);
define('DEPOSIT_COMMISSION', 0.03 / PERCENT);
define('WITHDRAW_BUSINESS_COMMISSION', 0.5 / PERCENT);
define('WITHDRAW_PRIVATE_COMMISSION', 0.3 / PERCENT);
define('FILE_COLUMN_SEPARATOR', ',');
define('MAXIMUM_FREE_TIMES', 3);
define('MAXIMUM_FREE_AMOUNT', 1000);

/**
 * COLUMN INDEX
 */
define('COLUMN_DATE', 0);
define('COLUMN_USER_ID', 1);
define('COLUMN_USER_TYPE', 2);
define('COLUMN_OPERATION_TYPE', 3);
define('COLUMN_OPERATION_AMOUNT', 4);
define('COLUMN_OPERATION_CURRENCY', 5);

class PsController extends Controller
{
    private $filePath;
    private $currencyExchangeRatesUrl;
    private $currencyExchangeRates;

    public function __construct()
    {
        $this->filePath = storage_path('app/transactions/t-1.csv');
        $this->fillExchangeRate();
    }

    /**
     * @return void
     */
    private function fillExchangeRate()
    {
        $this->currencyExchangeRatesUrl = storage_path('app/currency-exchange-rates.json');
        $this->currencyExchangeRates = file_get_contents($this->currencyExchangeRatesUrl);
        $this->currencyExchangeRates = json_decode($this->currencyExchangeRates, true);
        // TODO: Use it in production
        // $this->currencyExchangeRatesUrl = 'https://developers.paysera.com/tasks/api/currency-exchange-rates';
    }

    /**
     * @param $userId
     * @param $thatDate
     * @param $thatIndex
     * @return array
     *
     * This kind of method must be moved to a model as they will be working with the database and not just an CSV file.
     */
    private function withdrawalsInWeek($userId, $thatDate, $thatIndex): array
    {
        $handle = fopen($this->filePath, 'r');
        $thatDate = Carbon::parse($thatDate);
        $times = $amount = $thisIndex = 0;
        while (!feof($handle) && ($thisIndex < $thatIndex || $thatIndex == 0)) {
            $fields = fgetcsv($handle, 200, FILE_COLUMN_SEPARATOR);
            if (
                $fields &&
                $fields[COLUMN_USER_ID] == $userId &&
                $fields[COLUMN_USER_TYPE] == 'private' &&
                $fields[COLUMN_OPERATION_TYPE] == 'withdraw'
            ) {
                $thisDate = Carbon::parse($fields[COLUMN_DATE]);

                if (
                    $thisDate->diffInDays($thatDate) <= 7 &&
                    $thisDate->isSameWeek($thatDate) &&
                    $thisDate->lessThanOrEqualTo($thatDate)
                ) {
                    if ($fields[COLUMN_OPERATION_CURRENCY] !== 'EUR') {
                        $rate = $this->getCurrencyExchangeRate($fields[COLUMN_OPERATION_CURRENCY]);
                        $fields[COLUMN_OPERATION_AMOUNT] = $fields[COLUMN_OPERATION_AMOUNT] / $rate;
                    }

                    $amount += $fields[COLUMN_OPERATION_AMOUNT];
                    $times++;
                }
            }

            $thisIndex++;
        }

        return compact('times', 'amount');
    }

    /**
     * @param $currency
     * @return mixed
     */
    private function getCurrencyExchangeRate($currency)
    {
        return $this->currencyExchangeRates['rates'][$currency];
    }

    /**
     * @return void
     */
    public function index()
    {
        $handle = fopen($this->filePath, 'r');
        $index = 0;
        while (!feof($handle)) {
            $fields = fgetcsv($handle, 200, FILE_COLUMN_SEPARATOR);
            if ($fields) {
                if ($fields[COLUMN_OPERATION_TYPE] === 'withdraw') {
                    if ($fields[COLUMN_USER_TYPE] === 'private') {
                        /**
                         * It should iterate over the records line by line in each iteration
                         * since you mentioned not to use a database.
                         */
                        $withdrawalsInWeek = $this->withdrawalsInWeek($fields[COLUMN_USER_ID], $fields[COLUMN_DATE], $index);
                        if (
                            $withdrawalsInWeek['times'] > MAXIMUM_FREE_TIMES ||
                            $withdrawalsInWeek['amount'] > MAXIMUM_FREE_AMOUNT
                        ) {
                            if ($index == 0) {
                                $fields[COLUMN_OPERATION_AMOUNT] = abs(MAXIMUM_FREE_AMOUNT - $withdrawalsInWeek['amount']);
                            }

                            $this->showFormatted($fields[COLUMN_OPERATION_AMOUNT] * WITHDRAW_PRIVATE_COMMISSION);
                        } else {
                            if ($fields[COLUMN_OPERATION_CURRENCY] !== 'EUR') {
                                $currencyExchangeRates = file_get_contents($this->currencyExchangeRatesUrl);
                                $currencyExchangeRates = json_decode($currencyExchangeRates, true);
                                $rate = $currencyExchangeRates['rates'][$fields[COLUMN_OPERATION_CURRENCY]];
                                $amount = $fields[COLUMN_OPERATION_AMOUNT] / $rate;
                                $notFree = $amount - MAXIMUM_FREE_AMOUNT;
                                $notFree *= $rate;
                            } else {
                                $notFree = $withdrawalsInWeek['amount'];
                            }

                            if ($notFree > 0) {
                                $notFree *= WITHDRAW_PRIVATE_COMMISSION;
                            } else {
                                $notFree = 0;
                            }

                            $this->showFormatted($notFree);
                        }
                    } elseif ($fields[COLUMN_USER_TYPE] === 'business') {
                        $this->showFormatted($fields[COLUMN_OPERATION_AMOUNT] * WITHDRAW_BUSINESS_COMMISSION);
                    }
                } elseif ($fields[COLUMN_OPERATION_TYPE] === 'deposit') {
                    $this->showFormatted($fields[COLUMN_OPERATION_AMOUNT] * DEPOSIT_COMMISSION, 'deposit');
                }
            }
            $index++;
        }
        fclose($handle);
    }

    private function showFormatted($commission, $type = 'withdraw')
    {
        if ($type == 'withdraw') {
            dump(number_format(ceil(round($commission, 2) * 10) / 10, 2));
        } else {
            dump(number_format(round($commission, 2), 2));
        }
    }
}
