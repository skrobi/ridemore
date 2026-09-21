<?php
// core/Resources/PricingResource.php
namespace Resources;

use Models\EventPricing;

class PricingResource
{
    public static function fromModel(EventPricing $pricing): array
    {
        return [
            'amount'              => $pricing->amount,
            'currency'            => $pricing->currencyCode,
            'depositAmount'       => $pricing->depositAmount,
            'paymentDeadlineDays' => $pricing->paymentDeadlineDaysBefore,
            'cancellationDeadlineDays' => $pricing->cancellationDeadlineDaysBefore,
            'cancellationPolicy'  => $pricing->cancellationPolicy,
            'included'            => array_values(array_filter($pricing->items, fn($i) => $i['isIncluded'])),
            'excluded'            => array_values(array_filter($pricing->items, fn($i) => !$i['isIncluded'])),
        ];
    }
}
