<?php

namespace Dcat\Admin\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Dcat\Admin\Traits\ModelTree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\EloquentSortable\Sortable;

/**
 * Class Menu.
 *
 * @property int $id
 *
 * @method where($parent_id, $id)
 * @phpstan-consistent-constructor
 */
class Menu extends Model implements Sortable
{
    use HasDateTimeFormatter,
        MenuCache,
        ModelTree {
            allNodes as treeAllNodes;
            ModelTree::boot as treeBoot;
        }

    /**
     * @var array
     */
    protected $sortable = [
        'sort_when_creating' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['parent_id', 'order', 'title', 'icon', 'uri', 'extension', 'show'];

    /**
     * Create a new Eloquent model instance.
     *
     * @param  array  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->init();
    }

    protected function init()
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        $this->setConnection($connection);

        $this->setTable(config('admin.database.menu_table'));
    }

    /**
     * A Menu belongs to many roles.
     *
     * @return BelongsToMany
     */
    public function roles(): BelongsToMany
    {
        $pivotTable = config('admin.database.role_menu_table');

        $relatedModel = config('admin.database.roles_model');

        return $this->belongsToMany($relatedModel, $pivotTable, 'menu_id', 'role_id')->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        $pivotTable = config('admin.database.permission_menu_table');

        $relatedModel = config('admin.database.permissions_model');

        return $this->belongsToMany($relatedModel, $pivotTable, 'menu_id', 'permission_id')->withTimestamps();
    }

    /**
     * Get all elements.
     *
     * @param  bool  $force
     * @return static[]|\Illuminate\Support\Collection
     */
    public function allNodes(bool $force = false)
    {
        if ($force || $this->queryCallbacks) {
            return $this->fetchAll();
        }

        $nodes = $this->remember(function () {
            return $this->fetchAll()->map(function ($node) {
                $data = ['attributes' => $node->getAttributes(), 'relations' => []];
                foreach (['roles', 'permissions'] as $relation) {
                    if ($node->relationLoaded($relation)) {
                        $data['relations'][$relation] = $node->getRelation($relation)->map(function ($related) {
                            return [
                                'attributes' => $related->getAttributes(),
                                'pivot' => $related->relationLoaded('pivot') ? $related->getRelation('pivot')->getAttributes() : null,
                            ];
                        })->all();
                    }
                }

                return $data;
            })->all();
        });

        // Laravel 13 can disallow PHP object deserialization in cache stores.
        return $this->newCollection(array_map(function (array $data) {
            $node = $this->newFromBuilder($data['attributes']);
            foreach (['roles', 'permissions'] as $name) {
                if (! array_key_exists($name, $data['relations'])) {
                    continue;
                }
                $relation = $node->{$name}();
                $relatedModel = $relation->getRelated();
                $models = array_map(function (array $row) use ($relation, $relatedModel) {
                    $model = $relatedModel->newFromBuilder($row['attributes']);
                    if ($row['pivot'] !== null) {
                        $model->setRelation('pivot', $relation->newExistingPivot($row['pivot']));
                    }

                    return $model;
                }, $data['relations'][$name]);
                $node->setRelation($name, $relatedModel->newCollection($models));
            }

            return $node;
        }, $nodes));
    }

    /**
     * Fetch all elements.
     *
     * @return static[]|\Illuminate\Support\Collection
     */
    public function fetchAll()
    {
        return $this->withQuery(function ($query) {
            if (static::withPermission()) {
                $query = $query->with('permissions');
            }

            return $query->with('roles');
        })->treeAllNodes();
    }

    /**
     * Determine if enable menu bind permission.
     *
     * @return bool
     */
    public static function withPermission()
    {
        return config('admin.menu.bind_permission') && config('admin.permission.enable');
    }

    /**
     * Determine if enable menu bind role.
     *
     * @return bool
     */
    public static function withRole()
    {
        return (bool) config('admin.permission.enable');
    }

    /**
     * Detach models from the relationship.
     *
     * @return void
     */
    protected static function boot()
    {
        static::treeBoot();

        static::deleting(function ($model) {
            $model->roles()->detach();
            $model->permissions()->detach();

            $model->flushCache();
        });

        static::saved(function ($model) {
            $model->flushCache();
        });
    }
}
