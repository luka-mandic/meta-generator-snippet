<?php

namespace Mandic\MetaGenerator;

use Carbon;
use Mandic\MetaGenerator\Models\MerchantMetaTemplate;

/**
 * Class MerchantMetaGenerator
 *
 * 3 different versions for title and meta description:
 *
 *   1) merchants with many coupons (more than 1)
 *      with a short name (like Amazon, Douglas etc.)
 *      with a long name (like Caesars Entertainment, Daniel Wellington etc)
 *      with a very long name (like Electric City Experience today etc)
 *   2) merchants with 1 coupon
 *      with a short name (like EAST, Ecco etc.)
 *      with a long name (like Dell Inspiron Gaming, Electrical Experience etc)
 *      with a very long name (like Electric City Experience today etc)
 *   3) special case: merchants without any coupons
 *      with a short name (like EAST, Ecco etc.)
 *      with a long name (like Dell Inspiron Gaming, Electrical Experience etc)
 *      with a very long name (like Electric City Experience today etc)
 */

class MerchantMetaGenerator
{
    const MANY_COUPONS_SHORT_NAME = 1;
    const MANY_COUPONS_LONG_NAME = 2;
    const MANY_COUPONS_VERY_LONG_NAME = 3;
    const ONE_COUPON_SHORT_NAME = 4;
    const ONE_COUPON_LONG_NAME = 5;
    const ONE_COUPON_VERY_LONG_NAME = 6;
    const NO_COUPONS_SHORT_NAME = 7;
    const NO_COUPONS_LONG_NAME = 8;
    const NO_COUPONS_VERY_LONG_NAME = 9;

    const META_TITLE_LENGTH = 65;
    const META_DESCRIPTION_LENGTH = 155;

    private $merchant;
    private $couponCount;
    private $coupons;
    private $valueCoupons;
    private $date;
    private $maxValue;


    public function __construct($merchant)
    {
        $this->merchant = $merchant;
    }

    /**
     * @return array
     */
    public function handle()
    {

        $this->setUpData();

        $templates = MerchantMetaTemplate::where('locale_id', $this->merchant->locale->id)->get();

        // Case 3: merchants with no coupons or coupons that don't have a value
        if ($this->valueCoupons->count() == 0 || $this->couponCount == 0) {

            return $this->metaFieldsCreator(
                $templates->where('template_type', self::NO_COUPONS_SHORT_NAME)->first(),
                $templates->where('template_type', self::NO_COUPONS_LONG_NAME)->first(),
                $templates->where('template_type', self::NO_COUPONS_VERY_LONG_NAME)->first()
            );
        }

        // Extract max value from one of the value coupons
        $this->maxValue = $this->extractMaxValue();

        // Case 1: merchants with more than 1 coupon
        if ($this->couponCount > 1) {

            return $this->metaFieldsCreator(
                $templates->where('template_type', self::MANY_COUPONS_SHORT_NAME)->first(),
                $templates->where('template_type', self::MANY_COUPONS_LONG_NAME)->first(),
                $templates->where('template_type', self::MANY_COUPONS_VERY_LONG_NAME)->first()
            );
        }

        // Case 2: merchants with 1 coupon
        if ($this->couponCount == 1) {

            return $this->metaFieldsCreator(
                $templates->where('template_type', self::ONE_COUPON_SHORT_NAME)->first(),
                $templates->where('template_type', self::ONE_COUPON_LONG_NAME)->first(),
                $templates->where('template_type', self::ONE_COUPON_VERY_LONG_NAME)->first()
            );
        }

        return [
            'meta_title' => "",
            'meta_description' => "",
        ];
    }

    private function setUpData()
    {
        $this->coupons = $this->merchant->coupons()->where('status', 'publish')->get();
        $this->valueCoupons = $this->coupons->where('filter_type', '!=', null);
        $this->couponCount = $this->coupons->count();

        // Set the locale to the merchants locale so that the dates match the corresponding locale
        setlocale(LC_TIME, config('app.dateLang')[$this->merchant->locale->locale]);
        $this->date = Carbon\Carbon::now()->formatLocalized('%b, %Y');
    }

    /**
     * Generates meta title and description based on the templates given in the input
     *
     * @param $shortNameTemplate
     * @param $longNameTemplate
     * @param $veryLongNameTemplate
     * @return array
     */
    private function metaFieldsCreator($shortNameTemplate, $longNameTemplate, $veryLongNameTemplate)
    {
        $metaFields = $this->metaGenerator($shortNameTemplate);

        // If the fields adhere to the given character limits then use the short name template, if not use the long name templates
        if ($this->checkMetaLength($metaFields)) {
            return $metaFields;
        }

        $metaFields = $this->metaGenerator($longNameTemplate);

        if ($this->checkMetaLength($metaFields)) {
            return $metaFields;
        }


        return $this->metaGenerator($veryLongNameTemplate);
    }


    /**
     * @return string
     */
    private function extractMaxValue()
    {
        // We prefer the max value to be a percentage value, if there are no percentage values then use currency values
        $maxValueCoupon = $this->valueCoupons->where('filter_type', CouponFilter::PERCENTAGE_VALUE)->sortByDesc('numeric_value')->first();

        if ($maxValueCoupon == null) {
            $maxValueCoupon = $this->valueCoupons->where('filter_type', CouponFilter::CURRENCY_VALUE)->sortByDesc('numeric_value')->first();

            return $this->placeCurrencySymbol($maxValueCoupon);

        }

        return (int)$maxValueCoupon->numeric_value . '%';

    }

    /**
     * @param $template
     * @return array
     */
    private function metaGenerator($template)
    {
        $metaTitle = preg_replace('/\$merchant_name_variable/', $this->merchant->name, $template->meta_title);
        $metaTitle = preg_replace('/\$coupon_count_variable/', $this->couponCount, $metaTitle);
        $metaTitle = preg_replace('/\$max_saving_variable/', $this->maxValue, $metaTitle);
        $metaTitle = preg_replace('/\$date_variable/', $this->date, $metaTitle);


        $metaDescription = preg_replace('/\$merchant_name_variable/', $this->merchant->name, $template->meta_description);
        $metaDescription = preg_replace('/\$coupon_count_variable/', $this->couponCount, $metaDescription);
        $metaDescription = preg_replace('/\$max_saving_variable/', $this->maxValue, $metaDescription);
        $metaDescription = preg_replace('/\$date_variable/', $this->date, $metaDescription);

        return [
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
        ];
    }

    /**
     * Place currency symbol before or after value
     * 
     * @param $maxValueCoupon
     * @return string
     */
    private function placeCurrencySymbol($maxValueCoupon): string
    {
        if ($this->merchant->locale->currency_position == 'after') {
            return $maxValueCoupon->numeric_value . $this->merchant->locale->currency_symbol;
        }

        return $this->merchant->locale->currency_symbol . $maxValueCoupon->numeric_value;
    }

    /**
     * @param $metaFields
     * @return bool
     */
    private function checkMetaLength($metaFields)
    {
        if (strlen($metaFields['meta_title']) <= self::META_TITLE_LENGTH && strlen($metaFields['meta_description']) <= self::META_DESCRIPTION_LENGTH) {
            return true;
        }

        return false;
    }
}