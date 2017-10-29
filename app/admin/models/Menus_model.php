<?php namespace Admin\Models;

use DB;
use Igniter\Flame\ActivityLog\Traits\LogsActivity;
use Igniter\Flame\Database\Traits\Purgeable;
use Model;

/**
 * Menus Model Class
 *
 * @package Admin
 */
class Menus_model extends Model
{
    use LogsActivity;
    use Purgeable;

    /**
     * @var string The database table name
     */
    protected $table = 'menus';

    /**
     * @var string The database table primary key
     */
    protected $primaryKey = 'menu_id';

    protected $fillable = ['menu_name', 'menu_description', 'menu_price', 'menu_photo', 'menu_category_id',
        'stock_qty', 'minimum_qty', 'subtract_stock', 'mealtime_id', 'menu_status', 'menu_priority'];

    public $purgeable = [
        'special', 'menu_options', 'categories',
    ];

    public $relation = [
        'hasMany'       => [
            'menu_options'       => ['Admin\Models\Menu_item_options_model', 'delete' => TRUE],
            'menu_option_values' => ['Admin\Models\Menu_item_option_values_model'],
        ],
        'hasOne'        => [
            'special' => ['Admin\Models\Menus_specials_model', 'delete' => TRUE],
        ],
        'belongsTo'     => [
            'mealtime' => ['Admin\Models\Mealtimes_model'],
        ],
        'belongsToMany' => [
            'categories' => ['Admin\Models\Categories_model', 'table' => 'menu_categories', 'delete' => TRUE],
        ],
    ];

    public static $allowedSortingColumns = ['menu_priority asc', 'menu_priority desc'];

    //
    // Scopes
    //

    public function scopeListFrontEnd($query, $options = [])
    {
        extract(array_merge([
            'page'      => 1,
            'pageLimit' => 20,
            'sort'      => 'menu_priority asc',
            'group'     => null,
            'category'  => null,
        ], $options));

        if (strlen($category)) {
            $query->whereHas('categories', function ($q) use ($category) {
                $q->whereSlug($category);
            });
        }

        if (!is_array($sort)) {
            $sort = [$sort];
        }

        foreach ($sort as $_sort) {
            if (in_array($_sort, self::$allowedSortingColumns)) {
                $parts = explode(' ', $_sort);
                if (count($parts) < 2) {
                    array_push($parts, 'desc');
                }
                list($sortField, $sortDirection) = $parts;
                $query->orderBy($sortField, $sortDirection);
            }
        }

        if (strlen($group)) {
            $query->whereHas('categories', function ($q) use ($group) {
                $q->groupBy($group);
            });
        }

        return $query->paginate($pageLimit, $page);
    }

    /**
     * Filter database records
     *
     * @param $query
     * @param array $filter an associative array of field/value pairs
     *
     * @return $this
     */
    public function scopeFilter($query, $filter = [])
    {
        $current_date = DB::quote(mdate('%Y-%m-%d', time()));
        $current_time = DB::quote(mdate('%H:%i:%s', time()));

        $menusTable = $this->getTablePrefix('menus');
        $categoriesTable = $this->getTablePrefix('categories');
        $menusSpecialsTable = $this->getTablePrefix('menus_specials');
        $mealtimesTable = $this->getTablePrefix('mealtimes');

        if (APPDIR === ADMINDIR) {
            $queryBuilder = "*, {$menusTable}.menu_id, IF(start_date <= {$current_date}, IF(end_date >= {$current_date}, \"1\", \"0\"), \"0\") AS is_special";
        }
        else {
            $queryBuilder = "{$menusTable}.menu_id, menu_name, menu_description, menu_photo, menu_price, minimum_qty,
				{$categoriesTable}.category_id, menu_priority, {$categoriesTable}.name AS category_name, special_status,
				start_date, end_date, special_price, {$menusTable}.mealtime_id, {$mealtimesTable}.mealtime_name,
				{$mealtimesTable}.start_time, {$mealtimesTable}.end_time, mealtime_status, ".
                "IF({$menusSpecialsTable}.start_date <= {$current_date}, IF({$menusSpecialsTable}.end_date >= {$current_date}, \"1\", \"0\"), \"0\") AS is_special, ".
                "IF({$mealtimesTable}.start_time <= {$current_time}, IF({$mealtimesTable}.end_time >= {$current_time}, \"1\", \"0\"), \"0\") AS is_mealtime";
        }

        $query->selectRaw($queryBuilder);
        $query->leftJoin('categories', 'categories.category_id', '=', 'menus.menu_category_id');
        $query->leftJoin('menus_specials', 'menus_specials.menu_id', '=', 'menus.menu_id');
        $query->leftJoin('mealtimes', 'mealtimes.mealtime_id', '=', 'menus.mealtime_id');

        if (APPDIR === ADMINDIR) {
            if (isset($filter['filter_search']) AND is_string($filter['filter_search'])) {
                $query->search($filter['filter_search'], ['menu_name', 'menu_price', 'stock_qty']);
            }

            if (is_numeric($filter['filter_status'])) {
                $query->where('menu_status', $filter['filter_status']);
            }
        }

        if (!empty($filter['filter_category'])) {
            $query->where('category_id', $filter['filter_category']);
        }

        $query->groupBy('menus.menu_id');

        return $query;
    }

    //
    // Events
    //

    public function afterSave()
    {
        $this->restorePurgedValues();

        if (array_key_exists('special', $this->attributes))
            $this->addMenuSpecial($this->attributes['special']);

        if (array_key_exists('categories', $this->attributes)) {
            $menuCategories = $this->attributes['categories'];
            $this->addMenuCategories($menuCategories);
        }

        if (array_key_exists('menu_options', $this->attributes))
            $this->addMenuOption($this->attributes['menu_options']);
    }

    public function beforeDelete()
    {
        $this->addMenuCategories([]);
    }

    //
    // Helpers
    //

    public function getThumb($options = [])
    {
        return Image_tool_model::resize($this->menu_photo, array_merge([
            'width'  => is_numeric(setting('menu_images_w')) ? setting('menu_images_w') : '50',
            'height' => is_numeric(setting('menu_images_h')) ? setting('menu_images_h') : '50',
        ], $options));
    }

    public function hasOptions()
    {
        return count($this->menu_options);
    }

    /**
     * Subtract or add to menu stock quantity
     *
     * @param int $menu_id
     * @param int $quantity
     * @param string $action
     *
     * @return bool TRUE on success, or FALSE on failure
     */
    public function updateStock($quantity = 0, $action = 'subtract')
    {
        $update = FALSE;

        if ($this->subtract_stock == '1' AND !empty($quantity)) {
            $stock_qty = $this->stock_qty + $quantity;

            if ($action === 'subtract') {
                $stock_qty = $this->stock_qty - $quantity;
            }

            $update = $this->update(['stock_qty' => $stock_qty]);
        }

        return $update;
    }

    /**
     * List all menus matching the filter,
     * to fill select auto-complete options
     *
     * @param array $filter
     *
     * @return array
     */
    public static function getAutoComplete($filter = [])
    {
        $return = [];
        if (is_array($filter) AND !empty($filter)) {
            //selecting all records from the menu and categories tables.
            $query = self::query()->where('menu_status', '1');

            if (!empty($filter['menu_name'])) {
                $query->like('menu_name', $filter['menu_name']);
            }

            $limit = isset($filter['limit']) ? $filter['limit'] : 20;
            if ($results = $query->take($limit)->get()) {
                foreach ($results as $result) {
                    $return['results'][] = [
                        'id'   => $result['customer_id'],
                        'text' => utf8_encode($result['customer_name']),
                    ];
                }
            }
            else {
                $return['results'] = ['id' => '0', 'text' => lang('text_no_match')];
            }

            return $return;
        }
    }

    /**
     * Create new or update existing menu categories
     *
     * @param array $categoryIds if empty all existing records will be deleted
     *
     * @return bool
     */
    protected function addMenuCategories(array $categoryIds = [])
    {
        if (!$this->exists)
            return FALSE;

        $this->categories()->sync($categoryIds);
    }

    /**
     * Create new or update existing menu options
     *
     * @param bool $menuId
     * @param array $menuOptions if empty all existing records will be deleted
     *
     * @return bool
     */
    public function addMenuOption(array $menuOptions = [])
    {
        $menuId = $this->getKey();
        if (!is_numeric($menuId))
            return FALSE;

        $idsToKeep = [];
        foreach ($menuOptions as $option) {
            if (!isset($option['option_id'])) continue;

            $option['menu_id'] = $menuId;
            $menuOption = $this->menu_options()->updateOrCreate([
                'menu_option_id' => $option['menu_option_id'],
                'option_id'      => $option['option_id'],
            ], array_merge(array_except($option, 'menu_option_id'), [
                'option_values' => isset($option['menu_option_values']) ? serialize($option['menu_option_values']) : [],
            ]));

            if ($menuOption AND isset($option['menu_option_values'])) {
                $this->addMenuOptionValues($menuOption->getKey(), $option['option_id'], $option['menu_option_values']);
            }

            $idsToKeep[] = $menuOption->getKey();
        }

        $this->menu_options()->whereNotIn('menu_option_id', $idsToKeep)->delete();
        $this->menu_option_values()->whereNotIn('menu_option_id', $idsToKeep)->delete();
    }

    /**
     * Create new or update existing menu option values
     *
     * @param int $menuOptionId
     * @param int $optionId
     * @param array $optionValues if empty all existing records will be deleted
     */
    public function addMenuOptionValues($menuOptionId, $optionId, $optionValues = [])
    {
        $menuId = $this->getKey();
        if (!is_numeric($menuId))
            return FALSE;

        $idsToKeep = [];
        foreach ($optionValues as $value) {
            $menuOptionValue = $this->menu_option_values()->updateOrCreate([
                'menu_option_value_id' => $value['menu_option_value_id'],
                'menu_option_id'       => $menuOptionId,
            ], array_merge(array_except($value, 'menu_option_value_id'), [
                'menu_id'        => $menuId,
                'option_id'      => $optionId,
                'menu_option_id' => $menuOptionId,
            ]));

            $idsToKeep[] = $menuOptionValue->getKey();
        }

        $this->menu_option_values()
             ->where('menu_option_id', $menuOptionId)
             ->whereNotIn('menu_option_value_id', $idsToKeep)
             ->delete();
    }

    /**
     * Create new or update existing menu special
     *
     * @param bool $id
     * @param array $menuSpecial
     *
     * @return bool
     */
    protected function addMenuSpecial(array $menuSpecial = [])
    {
        $menuId = $this->getKey();
        if (!is_numeric($menuId) OR !isset($menuSpecial['special_id']))
            return FALSE;

        $menuSpecial['menu_id'] = $menuId;
        $this->special()->updateOrCreate([
            'special_id' => $menuSpecial['special_id'],
        ], array_except($menuSpecial, 'special_id'));
    }
}