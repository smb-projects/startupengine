<?php

namespace App;

use App\Traits\IsApiResource;
use App\Traits\RelationshipsTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Contracts\UserResolver;
use GrahamCampbell\Markdown\Facades\Markdown;
use Appstract\Meta\Metable;
use Conner\Tagging\Taggable;
use NexusPoint\Versioned\Versioned;
use App\Traits\hasJsonSchema;
use App\Traits\validateInputAgainstJsonSchema;
use Fico7489\Laravel\EloquentJoin\Traits\EloquentJoin;
use Symfony\Component\PropertyAccess\PropertyAccess;
use App\Scopes\GlobalPagesScope;

class Page extends Model implements \Altek\Accountant\Contracts\Recordable
{
    use \Altek\Accountant\Recordable;

    use EloquentJoin;

    use SoftDeletes;

    use IsApiResource;

    use Metable;

    use Taggable;

    use Versioned;

    use IsApiResource;

    use hasJsonSchema;

    use RelationshipsTrait;

    use validateInputAgainstJsonSchema;

    protected $casts = [
        'schema' => 'json'
    ];

    protected $fillable = ['title', 'deleted_at', 'schema'];

    /**
     * Field from the model to use as the versions name
     * @var string
     */
    protected $versionNameColumn = 'title';

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['deleted_at', 'created_at', 'updated_at'];

    /**
     * Attributes to include in the Audit.
     *
     * @var array
     */
    protected $auditInclude = [
        'title',
        'meta_excerpt',
        'meta_description',
        'json',
        'user_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];
    /**
     * Auditable events.
     *
     * @var array
     */
    protected $auditableEvents = ['created', 'updated', 'deleted', 'restored'];

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new GlobalPagesScope());
    }

    public function searchFields()
    {
        return ['title', 'slug', 'json'];
    }

    public function raw($path)
    {
        $url =
            "https://raw.githubusercontent.com/" .
            env('GITHUB_USERNAME') .
            "/" .
            env('GITHUB_REPOSITORY') .
            "/" .
            env("GITHUB_REPOSITORY_BRANCH") .
            "/pages/" .
            $path;
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode == 404) {
            return null;
            curl_close($curl);
        } else {
            curl_close($curl);
            return $output;
        }
    }

    public function json()
    {
        if ($this->json != null) {
            $json = json_decode($this->json);
        } else {
            $json = null;
        }
        return $json;
    }

    public function thumbnail()
    {
        //$json = $this->content();
        if ($this->content() != null && isset($this->content()->sections)) {
            if (isset($this->schema()->sectoins)) {
                foreach ($this->schema()->sections as $section) {
                    if ($section->fields != null) {
                        foreach ($section->fields as $field => $value) {
                            if (
                                isset($value->isThumbnail) &&
                                $value->isThumbnail == true
                            ) {
                                $slug = $section->slug;
                                $string =
                                    "sections->" .
                                    $slug .
                                    "->fields->" .
                                    $field;
                                if (
                                    isset(
                                        $this->content()->sections->$slug
                                            ->fields->$field
                                    )
                                ) {
                                    return $this->content()->sections->$slug
                                        ->fields->$field;
                                } else {
                                    return null;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function content()
    {
        $json = $this->json;
        if ($json != null && gettype($json) != 'object') {
            $array = json_decode(json_encode($json));
        } else {
            $array = [];
        }

        if (gettype($json) == 'string') {
            $json = json_decode($json);
        }

        return json_decode(json_encode($json));
    }

    public function schema()
    {
        return json_decode(json_encode($this->getAttributeValue('schema')));
    }

    public function markdown($content)
    {
        return Markdown::convertToHtml($content);
    }

    public function schemaToString()
    {
        if ($this->schema() !== null) {
            return json_encode($this->schema());
        } else {
            return null;
        }
    }

    public function versions()
    {
        $json = json_decode($this->json, true);
        $versions = count($json['versions']);
        if ($versions == null) {
            $versions = 0;
        }

        return $versions;
    }

    public function views()
    {
        $request = request();
        if ($request->input('startDate') != null) {
            $startDate = \Carbon\Carbon::parse($request->input('startDate'));
        } else {
            $startDate = new Carbon();
            $startDate = $startDate->subDays(30);
        }
        if ($request->input('endDate') != null) {
            $endDate = \Carbon\Carbon::parse($request->input('endDate'));
        } else {
            $endDate = new Carbon();
        }
        /*
      $startDate = \Carbon\Carbon::now()->subDays(30)->toDateTimeString();
      $endDate = \Carbon\Carbon::now()->subDays(0)->toDateTimeString();
      $views = $item->views()->where('created_at', '>=', $startDate)->where('created_at', '<=', $endDate)->get();
      dd($views);
      */
        //dd($endDate);
        $views = $this->hasMany('App\AnalyticEvent', 'model_id')
            ->where('event_type', '=', 'page viewed')
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate);
        return $views;
    }

    public function isDefaultPage()
    {
        $schema = $this->schema();
        if (gettype($schema) == 'string') {
            $schema = json_decode($schema);
        }
        if (isset($this->schema()->category)) {
            $category = $this->schema()->category;
        } else {
            $category = null;
        }

        if ($category == 'defaults') {
            return true;
        } else {
            return false;
        }
    }

    public function standardSchema()
    {
        $path = file_get_contents(storage_path() . '/schemas/post.json');
        $baseSchema = json_decode($path, true);

        return json_decode(
            json_encode(
                array_merge($baseSchema, json_decode($this->schema(), true))
            )
        );
    }

    public function toJsonResponse($input)
    {
        if (gettype($input) == 'string') {
            return json_decode($input);
        } else {
            return $input;
        }
    }

    public function undelete()
    {
        $this->deleted_at = null;
        $this->save();
        $this->restore();
        ob_start();
        $this->setDeletedAtAttribute(null);
        ob_end_clean();
    }
}
