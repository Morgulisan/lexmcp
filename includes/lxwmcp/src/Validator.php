<?php
declare(strict_types=1);

namespace LexMcp;

final class Validator
{
    private const NESTED_ENUMS = [
        'price.leadingPrice' => ['NET','GROSS'],
        'taxConditions.taxType' => ['gross','net','vatfree','intraCommunitySupply','constructionService13b','externalService13b','thirdPartyCountryService','thirdPartyCountryDelivery','photovoltaicEquipment'],
        'taxConditions.taxSubType' => ['distanceSales','electronicServices'],
        'lineItems[].type' => ['custom','material','service','text'],
        'shippingConditions.shippingType' => ['service','serviceperiod','delivery','deliveryperiod','none'],
        'totalPrice.currency' => ['EUR'],
        'language' => ['de','en'],
    ];

    public static function nestedEnums(array $definition): array
    {
        if (($definition['sales'] ?? false) === true) {
            $fields = $definition['fields'] ?? [];
            return array_filter(self::NESTED_ENUMS, static fn(string $path): bool => in_array(str_replace('[]', '', explode('.', $path)[0]), $fields, true), ARRAY_FILTER_USE_KEY);
        }
        if (($definition['article'] ?? false) === true) return ['price.leadingPrice' => self::NESTED_ENUMS['price.leadingPrice']];
        return [];
    }

    public function __construct(private readonly AliasResolver $aliases) {}

    public function parameters(array $raw, array $definition): array
    {
        $allowed = $definition['parameters'] ?? $definition['fields'] ?? [];
        if (($definition['sales'] ?? false) === true && !isset($definition['fields'])) {
            $allowed = require Config::endpointFile();
            $allowed = $allowed['salesFields'];
        }
        $params = $this->aliases->normalizeParameters($raw, $allowed);
        foreach ($definition['required'] ?? [] as $required) {
            if (!array_key_exists($required, $params)) {
                throw new AppError('validation_error', "Required parameter '{$required}' is missing.", 400, false, ['field' => $required]);
            }
        }
        if (isset($params['page']) && (!is_int($params['page']) || $params['page'] < 0)) {
            throw new AppError('validation_error', 'page must be a non-negative integer.', 400, false, ['field' => 'page']);
        }
        if (isset($params['size'])) {
            $max = (int) ($definition['maxSize'] ?? 250);
            if (!is_int($params['size']) || $params['size'] < 1 || $params['size'] > $max) {
                throw new AppError('validation_error', "size must be between 1 and {$max}.", 400, false, ['field' => 'size']);
            }
        }
        foreach ($definition['enums'] ?? [] as $field => $values) {
            if (!array_key_exists($field, $params)) {
                continue;
            }
            if (!is_string($params[$field])) {
                throw new AppError('validation_error', "{$field} must be a string enum value.", 400, false, ['field' => $field]);
            }
            $params[$field] = $this->aliases->enum($params[$field], $values, 'parameters.' . $field);
        }
        foreach ($definition['enumLists'] ?? [] as $field => $values) {
            if (!array_key_exists($field, $params)) {
                continue;
            }
            if (!is_string($params[$field])) {
                throw new AppError('validation_error', "{$field} must be a comma-separated string.", 400, false, ['field' => $field]);
            }
            $normalized = [];
            foreach (explode(',', $params[$field]) as $item) {
                $normalized[] = $this->aliases->enum(trim($item), $values, 'parameters.' . $field);
            }
            $params[$field] = implode(',', array_values(array_unique($normalized)));
        }
        foreach ($definition['minLength'] ?? [] as $field => $minimum) {
            if (isset($params[$field]) && (!is_string($params[$field]) || mb_strlen($params[$field]) < $minimum)) {
                throw new AppError('validation_error', "{$field} must contain at least {$minimum} characters.", 400, false, ['field' => $field]);
            }
        }
        $this->validateKnownNestedObjects($params, $definition);
        $this->validateScalarTypes($params, ($definition['sales'] ?? false) === true);
        if (($definition['contact'] ?? false) === true) {
            $this->validateContact($params, ($definition['create'] ?? false) === true);
        }
        if (($definition['article'] ?? false) === true) {
            $this->validateArticle($params);
        }
        if (($definition['voucher'] ?? false) === true) {
            $this->validateVoucher($params, ($definition['create'] ?? false) === true);
        }
        if (($definition['sales'] ?? false) === true) {
            $this->validateSalesVoucher($params, ($definition['invoice'] ?? false) === true);
        }
        return $params;
    }

    public function uuid(string $value, string $field = 'id'): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new AppError('validation_error', "{$field} must be a UUID.", 400, false, ['field' => $field]);
        }
        return strtolower($value);
    }

    public function idempotencyKey(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $value) !== 1) {
            throw new AppError('validation_error', 'idempotency_key must contain 8-128 safe characters.', 400, false, ['field' => 'idempotency_key']);
        }
        return $value;
    }

    private function validateKnownNestedObjects(array &$params, array $definition): void
    {
        $maps = [
            'price' => ['netPrice','grossPrice','leadingPrice','taxRate'],
            'voucherItems' => ['amount','taxAmount','taxRatePercent','categoryId'],
            'address' => ['contactId','name','supplement','street','city','zip','countryCode'],
            'lineItems' => ['id','type','name','description','quantity','unitName','unitPrice','discountPercentage'],
            'unitPrice' => ['currency','netAmount','grossAmount','taxRatePercentage'],
            'totalPrice' => ['currency','totalDiscountAbsolute','totalDiscountPercentage'],
            'taxConditions' => ['taxType','taxSubType','taxTypeNote'],
            'paymentConditions' => ['paymentTermLabel','paymentTermDuration','paymentDiscountConditions'],
            'shippingConditions' => ['shippingDate','shippingEndDate','shippingType'],
            'xRechnung' => ($definition['contact'] ?? false) === true ? ['buyerReference','vendorNumberAtCustomer'] : ['buyerReference'],
        ];
        foreach ($maps as $field => $allowed) {
            if (!array_key_exists($field, $params)) {
                continue;
            }
            $value = $params[$field];
            if (in_array($field, ['voucherItems','lineItems'], true)) {
                if (!is_array($value) || !array_is_list($value)) {
                    throw new AppError('validation_error', "{$field} must be a list.", 400, false, ['field' => $field]);
                }
                foreach ($value as $index => $row) {
                    if (!is_array($row)) {
                        throw new AppError('validation_error', "{$field}[{$index}] must be an object.", 400);
                    }
                    $value[$index] = $this->aliases->normalizeParameters($row, $allowed);
                    if ($field === 'voucherItems') {
                        foreach (['amount','taxAmount','taxRatePercent','categoryId'] as $required) {
                            if (!array_key_exists($required, $value[$index])) {
                                throw new AppError('validation_error', "{$field}[{$index}].{$required} is required.", 400, false, ['field' => "{$field}[{$index}].{$required}"]);
                            }
                        }
                    }
                    if (isset($value[$index]['unitPrice']) && is_array($value[$index]['unitPrice'])) {
                        $value[$index]['unitPrice'] = $this->aliases->normalizeParameters($value[$index]['unitPrice'], $maps['unitPrice']);
                    }
                }
                $params[$field] = $value;
            } elseif (is_array($value)) {
                $params[$field] = $this->aliases->normalizeParameters($value, $allowed);
            } else {
                throw new AppError('validation_error', "{$field} must be an object.", 400, false, ['field' => $field]);
            }
        }
        if (isset($params['lineItems']) && count($params['lineItems']) > 300) {
            throw new AppError('validation_error', 'lineItems may contain at most 300 entries.', 400, false, ['field' => 'lineItems']);
        }
        if (isset($params['price']['leadingPrice']) && is_string($params['price']['leadingPrice'])) {
            $params['price']['leadingPrice'] = $this->aliases->enum($params['price']['leadingPrice'], self::NESTED_ENUMS['price.leadingPrice'], 'parameters.price.leadingPrice');
        }
        if (isset($params['paymentConditions']['paymentDiscountConditions'])) {
            $discount = $params['paymentConditions']['paymentDiscountConditions'];
            if (!is_array($discount) || array_is_list($discount)) {
                throw new AppError('validation_error', 'paymentDiscountConditions must be an object.', 400);
            }
            $params['paymentConditions']['paymentDiscountConditions'] = $this->aliases->normalizeParameters($discount, ['discountPercentage','discountRange']);
        }
    }

    private function validateContact(array &$params, bool $create): void
    {
        if ($create && ($params['version'] ?? null) !== 0) {
            throw new AppError('validation_error', 'A new contact requires version 0.', 400, false, ['field' => 'version']);
        }
        $hasCompany = isset($params['company']);
        $hasPerson = isset($params['person']);
        if ($hasCompany === $hasPerson) {
            throw new AppError('validation_error', 'A contact requires exactly one of company or person.', 400);
        }
        if (!is_array($params['roles']) || array_is_list($params['roles'])) {
            throw new AppError('validation_error', 'roles must be an object.', 400, false, ['field' => 'roles']);
        }
        $params['roles'] = $this->aliases->normalizeParameters($params['roles'], ['customer','vendor']);
        if ($params['roles'] === []) {
            throw new AppError('validation_error', 'A contact requires at least one role.', 400, false, ['field' => 'roles']);
        }
        foreach ($params['roles'] as $role => $value) {
            if (!is_array($value) || $value !== []) {
                throw new AppError('validation_error', "roles.{$role} must be an empty object for writes.", 400, false, ['field' => "roles.{$role}"]);
            }
        }
        if ($hasCompany) {
            if (!is_array($params['company'])) throw new AppError('validation_error', 'company must be an object.', 400);
            $params['company'] = $this->aliases->normalizeParameters($params['company'], ['name','taxNumber','vatRegistrationId','allowTaxFreeInvoices','contactPersons']);
            if (!is_string($params['company']['name'] ?? null) || trim($params['company']['name']) === '') {
                throw new AppError('validation_error', 'company.name is required.', 400, false, ['field' => 'company.name']);
            }
            if (isset($params['company']['contactPersons'])) {
                $persons = $params['company']['contactPersons'];
                if (!is_array($persons) || !array_is_list($persons) || count($persons) > 1) {
                    throw new AppError('validation_error', 'company.contactPersons may contain at most one entry.', 400);
                }
                foreach ($persons as $index => $person) {
                    if (!is_array($person)) throw new AppError('validation_error', "company.contactPersons[{$index}] must be an object.", 400);
                    $persons[$index] = $this->aliases->normalizeParameters($person, ['salutation','firstName','lastName','primary','emailAddress','phoneNumber']);
                    if (!is_string($persons[$index]['lastName'] ?? null) || trim($persons[$index]['lastName']) === '') {
                        throw new AppError('validation_error', "company.contactPersons[{$index}].lastName is required.", 400);
                    }
                }
                $params['company']['contactPersons'] = $persons;
            }
        }
        if ($hasPerson) {
            if (!is_array($params['person'])) throw new AppError('validation_error', 'person must be an object.', 400);
            $params['person'] = $this->aliases->normalizeParameters($params['person'], ['salutation','firstName','lastName']);
            if (!is_string($params['person']['lastName'] ?? null) || trim($params['person']['lastName']) === '') {
                throw new AppError('validation_error', 'person.lastName is required.', 400, false, ['field' => 'person.lastName']);
            }
        }
        if (isset($params['addresses'])) {
            $params['addresses'] = $this->normalizeContactLists($params['addresses'], ['billing','shipping'], ['supplement','street','zip','city','countryCode'], true);
        }
        if (isset($params['emailAddresses'])) {
            $params['emailAddresses'] = $this->normalizeContactLists($params['emailAddresses'], ['business','office','private','other'], null, false);
        }
        if (isset($params['phoneNumbers'])) {
            $params['phoneNumbers'] = $this->normalizeContactLists($params['phoneNumbers'], ['business','office','mobile','private','fax','other'], null, false);
        }
        if (isset($params['xRechnung'])) {
            if (!is_array($params['xRechnung'])) throw new AppError('validation_error', 'xRechnung must be an object.', 400);
            $params['xRechnung'] = $this->aliases->normalizeParameters($params['xRechnung'], ['buyerReference','vendorNumberAtCustomer']);
        }
        if (isset($params['note']) && (!is_string($params['note']) || mb_strlen($params['note']) > 1000)) {
            throw new AppError('validation_error', 'note may contain at most 1000 characters.', 400, false, ['field' => 'note']);
        }
    }

    private function normalizeContactLists(mixed $value, array $types, ?array $itemFields, bool $requireCountry): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new AppError('validation_error', 'Contact list container must be an object.', 400);
        }
        $result = $this->aliases->normalizeParameters($value, $types);
        foreach ($result as $type => $items) {
            if (!is_array($items) || !array_is_list($items) || count($items) > 1) {
                throw new AppError('validation_error', "{$type} may contain at most one entry.", 400);
            }
            if ($itemFields === null) {
                foreach ($items as $item) {
                    if (!is_string($item)) throw new AppError('validation_error', "{$type} entries must be strings.", 400);
                }
                continue;
            }
            foreach ($items as $index => $item) {
                if (!is_array($item)) throw new AppError('validation_error', "{$type}[{$index}] must be an object.", 400);
                $items[$index] = $this->aliases->normalizeParameters($item, $itemFields);
                if ($requireCountry && is_string($items[$index]['countryCode'] ?? null)) {
                    $items[$index]['countryCode'] = strtoupper($items[$index]['countryCode']);
                }
                if ($requireCountry && (!is_string($items[$index]['countryCode'] ?? null) || preg_match('/^[A-Z]{2}$/', $items[$index]['countryCode']) !== 1)) {
                    throw new AppError('validation_error', "{$type}[{$index}].countryCode must be an ISO alpha-2 code.", 400);
                }
            }
            $result[$type] = $items;
        }
        return $result;
    }

    private function validateScalarTypes(array &$params, bool $sales): void
    {
        foreach (['customer','vendor','archived','useCollectiveContact'] as $field) {
            if (isset($params[$field]) && !is_bool($params[$field])) {
                throw new AppError('validation_error', "{$field} must be a boolean.", 400, false, ['field' => $field]);
            }
        }
        foreach (['number','version'] as $field) {
            if (isset($params[$field]) && (!is_int($params[$field]) || $params[$field] < 0)) {
                throw new AppError('validation_error', "{$field} must be a non-negative integer.", 400, false, ['field' => $field]);
            }
        }
        foreach (['contactId','printLayoutId','precedingSalesVoucherId'] as $field) {
            if (isset($params[$field])) {
                if (!is_string($params[$field])) throw new AppError('validation_error', "{$field} must be a UUID.", 400);
                $params[$field] = $this->uuid($params[$field], $field);
            }
        }
        foreach (['voucherDate','shippingDate','dueDate'] as $field) {
            if (!isset($params[$field])) {
                continue;
            }
            $valid = is_string($params[$field]) && strtotime($params[$field]) !== false
                && ($sales ? preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/', $params[$field]) === 1 : preg_match('/^\d{4}-\d{2}-\d{2}$/', $params[$field]) === 1);
            if (!$valid) {
                $format = $sales ? 'an RFC 3339 date-time with timezone' : 'YYYY-MM-DD';
                throw new AppError('validation_error', "{$field} must use {$format}.", 400, false, ['field' => $field]);
            }
        }
        foreach (['voucherDateFrom','voucherDateTo','createdDateFrom','createdDateTo','updatedDateFrom','updatedDateTo'] as $field) {
            if (isset($params[$field]) && (!is_string($params[$field]) || strtotime($params[$field]) === false)) {
                throw new AppError('validation_error', "{$field} must be an ISO date or date-time.", 400, false, ['field' => $field]);
            }
        }
    }

    private function validateArticle(array $params): void
    {
        $price = $params['price'] ?? null;
        if (!is_array($price)) return;
        $leading = $price['leadingPrice'] ?? null;
        if (!in_array($leading, ['NET','GROSS'], true)) {
            throw new AppError('validation_error', 'price.leadingPrice must be NET or GROSS.', 400);
        }
        $requiredPrice = $leading === 'NET' ? 'netPrice' : 'grossPrice';
        if (!is_int($price[$requiredPrice] ?? null) && !is_float($price[$requiredPrice] ?? null)) {
            throw new AppError('validation_error', "price.{$requiredPrice} is required and must be numeric.", 400);
        }
        if ((!is_int($price['taxRate'] ?? null) && !is_float($price['taxRate'] ?? null)) || !in_array((float) $price['taxRate'], [0.0, 7.0, 19.0], true)) {
            throw new AppError('validation_error', 'price.taxRate must be 0, 7, or 19.', 400);
        }
    }

    private function validateVoucher(array $params, bool $create): void
    {
        if ($create && array_key_exists('version', $params) && $params['version'] !== 1) {
            throw new AppError('validation_error', 'A version included when creating a voucher must be 1.', 400, false, ['field' => 'version']);
        }
        $status = $params['voucherStatus'] ?? 'open';
        if ($status === 'unchecked' && ($params['taxType'] ?? null) === 'net') {
            throw new AppError('validation_error', 'Lexware does not permit unchecked net vouchers.', 400);
        }
        if (in_array($params['type'] ?? null, ['purchaseinvoice','purchasecreditnote'], true) && isset($params['shippingDate'])) {
            throw new AppError('validation_error', 'shippingDate is only supported for salesinvoice and salescreditnote bookkeeping vouchers.', 400);
        }
        $gross = 0.0;
        $tax = 0.0;
        if (isset($params['voucherItems'])) {
            foreach ($params['voucherItems'] as $index => $item) {
                foreach (['amount','taxAmount','taxRatePercent'] as $field) {
                    if (!is_int($item[$field]) && !is_float($item[$field])) {
                        throw new AppError('validation_error', "voucherItems[{$index}].{$field} must be numeric.", 400);
                    }
                }
                if (!is_string($item['categoryId'])) {
                    throw new AppError('validation_error', "voucherItems[{$index}].categoryId must be a UUID.", 400);
                }
                $this->uuid($item['categoryId'], "voucherItems[{$index}].categoryId");
                $gross += (float) $item['amount'] + (($params['taxType'] ?? null) === 'net' ? (float) $item['taxAmount'] : 0.0);
                $tax += (float) $item['taxAmount'];
            }
        }
        if (isset($params['files'])) {
            if (!is_array($params['files']) || !array_is_list($params['files'])) {
                throw new AppError('validation_error', 'files must be a list of UUIDs.', 400);
            }
            foreach ($params['files'] as $index => $file) {
                if (!is_string($file)) throw new AppError('validation_error', "files[{$index}] must be a UUID.", 400);
                $this->uuid($file, "files[{$index}]");
            }
        }
        if ($status !== 'open') return;
        foreach (['voucherNumber','voucherDate','totalGrossAmount','totalTaxAmount','voucherItems'] as $required) {
            if (!array_key_exists($required, $params)) {
                throw new AppError('validation_error', "{$required} is required for an open voucher.", 400, false, ['field' => $required]);
            }
        }
        if (($params['useCollectiveContact'] ?? false) !== true && !isset($params['contactId'])) {
            throw new AppError('validation_error', 'contactId is required unless useCollectiveContact is true.', 400);
        }
        foreach (['totalGrossAmount','totalTaxAmount'] as $field) {
            if (!is_int($params[$field]) && !is_float($params[$field])) {
                throw new AppError('validation_error', "{$field} must be numeric.", 400, false, ['field' => $field]);
            }
        }
        if (abs($gross - (float) $params['totalGrossAmount']) > 0.011 || abs($tax - (float) $params['totalTaxAmount']) > 0.011) {
            throw new AppError('validation_error', 'Voucher totals do not match voucherItems.', 400);
        }
    }

    private function validateSalesVoucher(array &$params, bool $invoice): void
    {
        $address = $params['address'] ?? null;
        if (!is_array($address) || array_is_list($address)) {
            throw new AppError('validation_error', 'address must be an object.', 400, false, ['field' => 'address']);
        }
        if (isset($address['contactId'])) {
            if (!is_string($address['contactId'])) {
                throw new AppError('validation_error', 'address.contactId must be a UUID.', 400);
            }
            $params['address']['contactId'] = $this->uuid($address['contactId'], 'address.contactId');
        } else {
            foreach (['name','countryCode'] as $required) {
                if (!is_string($address[$required] ?? null) || trim($address[$required]) === '') {
                    throw new AppError('validation_error', "address.{$required} is required for a one-time address.", 400, false, ['field' => "address.{$required}"]);
                }
            }
            $params['address']['countryCode'] = strtoupper($address['countryCode']);
            if (preg_match('/^[A-Z]{2}$/', $params['address']['countryCode']) !== 1) {
                throw new AppError('validation_error', 'address.countryCode must be an ISO alpha-2 code.', 400);
            }
        }

        $taxTypes = self::NESTED_ENUMS['taxConditions.taxType'];
        $tax = $params['taxConditions'] ?? null;
        if (!is_array($tax) || !is_string($tax['taxType'] ?? null)) {
            throw new AppError('validation_error', 'taxConditions.taxType is required.', 400, false, ['field' => 'taxConditions.taxType']);
        }
        $params['taxConditions']['taxType'] = $this->aliases->enum($tax['taxType'], $taxTypes, 'parameters.taxConditions.taxType');
        if (array_key_exists('taxSubType', $tax) && $tax['taxSubType'] !== null) {
            if (!is_string($tax['taxSubType'])) {
                throw new AppError('validation_error', 'taxConditions.taxSubType must be a string or null.', 400);
            }
            $params['taxConditions']['taxSubType'] = $this->aliases->enum($tax['taxSubType'], self::NESTED_ENUMS['taxConditions.taxSubType'], 'parameters.taxConditions.taxSubType');
        }

        $total = $params['totalPrice'] ?? null;
        if (!is_array($total) || !in_array($total['currency'] ?? null, self::NESTED_ENUMS['totalPrice.currency'], true)) {
            throw new AppError('validation_error', 'totalPrice.currency must be EUR.', 400, false, ['field' => 'totalPrice.currency']);
        }
        foreach (['totalDiscountAbsolute','totalDiscountPercentage'] as $field) {
            if (isset($total[$field]) && !is_int($total[$field]) && !is_float($total[$field])) {
                throw new AppError('validation_error', "totalPrice.{$field} must be numeric.", 400, false, ['field' => "totalPrice.{$field}"]);
            }
        }
        if (isset($params['language']) && !in_array(strtolower((string) $params['language']), self::NESTED_ENUMS['language'], true)) {
            throw new AppError('validation_error', 'language must be de or en.', 400, false, ['field' => 'language']);
        }
        if (isset($params['language'])) {
            $params['language'] = strtolower((string) $params['language']);
        }

        foreach ($params['lineItems'] as $index => &$item) {
            if (!is_string($item['type'] ?? null)) {
                throw new AppError('validation_error', "lineItems[{$index}].type is required.", 400);
            }
            $item['type'] = $this->aliases->enum($item['type'], self::NESTED_ENUMS['lineItems[].type'], "parameters.lineItems[{$index}].type");
            if ($item['type'] === 'text') {
                if ((!is_string($item['name'] ?? null) || trim($item['name']) === '') && (!is_string($item['description'] ?? null) || trim($item['description']) === '')) {
                    throw new AppError('validation_error', "lineItems[{$index}] text requires name or description.", 400);
                }
                continue;
            }
            if (!$invoice && array_key_exists('discountPercentage', $item)) {
                throw new AppError('validation_error', "lineItems[{$index}].discountPercentage is not supported for credit notes.", 400);
            }
            foreach (['name','unitName'] as $required) {
                if (!is_string($item[$required] ?? null) || trim($item[$required]) === '') {
                    throw new AppError('validation_error', "lineItems[{$index}].{$required} is required.", 400);
                }
            }
            if (!is_int($item['quantity'] ?? null) && !is_float($item['quantity'] ?? null)) {
                throw new AppError('validation_error', "lineItems[{$index}].quantity must be numeric.", 400);
            }
            if (in_array($item['type'], ['material','service'], true)) {
                if (!is_string($item['id'] ?? null)) {
                    throw new AppError('validation_error', "lineItems[{$index}].id is required for referenced items.", 400);
                }
                $item['id'] = $this->uuid($item['id'], "lineItems[{$index}].id");
            }
            $unit = $item['unitPrice'] ?? null;
            if (!is_array($unit) || ($unit['currency'] ?? null) !== 'EUR') {
                throw new AppError('validation_error', "lineItems[{$index}].unitPrice.currency must be EUR.", 400);
            }
            $amountField = $params['taxConditions']['taxType'] === 'gross' ? 'grossAmount' : 'netAmount';
            if (!is_int($unit[$amountField] ?? null) && !is_float($unit[$amountField] ?? null)) {
                throw new AppError('validation_error', "lineItems[{$index}].unitPrice.{$amountField} must be numeric.", 400);
            }
            if (!is_int($unit['taxRatePercentage'] ?? null) && !is_float($unit['taxRatePercentage'] ?? null)) {
                throw new AppError('validation_error', "lineItems[{$index}].unitPrice.taxRatePercentage must be numeric.", 400);
            }
            if (!in_array($params['taxConditions']['taxType'], ['net','gross'], true) && (float) $unit['taxRatePercentage'] !== 0.0) {
                throw new AppError('validation_error', "lineItems[{$index}].unitPrice.taxRatePercentage must be 0 for vat-free tax types.", 400);
            }
        }
        unset($item);

        if ($invoice) {
            $shipping = $params['shippingConditions'] ?? null;
            if (!is_array($shipping) || !is_string($shipping['shippingType'] ?? null)) {
                throw new AppError('validation_error', 'shippingConditions.shippingType is required.', 400);
            }
            $params['shippingConditions']['shippingType'] = $this->aliases->enum($shipping['shippingType'], self::NESTED_ENUMS['shippingConditions.shippingType'], 'parameters.shippingConditions.shippingType');
            $type = $params['shippingConditions']['shippingType'];
            if ($type !== 'none') {
                $this->assertRfc3339($shipping['shippingDate'] ?? null, 'shippingConditions.shippingDate');
            }
            if (in_array($type, ['serviceperiod','deliveryperiod'], true)) {
                $this->assertRfc3339($shipping['shippingEndDate'] ?? null, 'shippingConditions.shippingEndDate');
                if (strtotime((string) $shipping['shippingEndDate']) < strtotime((string) $shipping['shippingDate'])) {
                    throw new AppError('validation_error', 'shippingConditions.shippingEndDate must not precede shippingDate.', 400);
                }
            }
            if (isset($params['xRechnung']) && !array_key_exists('buyerReference', $params['xRechnung'])) {
                throw new AppError('validation_error', 'xRechnung.buyerReference is required when xRechnung is supplied.', 400);
            }
        }
        if (isset($params['paymentConditions'])) {
            $payment = $params['paymentConditions'];
            if (isset($payment['paymentTermDuration']) && (!is_int($payment['paymentTermDuration']) || $payment['paymentTermDuration'] < 0)) {
                throw new AppError('validation_error', 'paymentConditions.paymentTermDuration must be a non-negative integer.', 400);
            }
            if (isset($payment['paymentDiscountConditions'])) {
                $discount = $payment['paymentDiscountConditions'];
                if ((!is_int($discount['discountPercentage'] ?? null) && !is_float($discount['discountPercentage'] ?? null)) || !is_int($discount['discountRange'] ?? null) || $discount['discountRange'] < 0) {
                    throw new AppError('validation_error', 'paymentDiscountConditions requires numeric discountPercentage and a non-negative integer discountRange.', 400);
                }
            }
        }
    }

    private function assertRfc3339(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1 || strtotime($value) === false) {
            throw new AppError('validation_error', "{$field} must be an RFC 3339 date-time with timezone.", 400, false, ['field' => $field]);
        }
    }
}
