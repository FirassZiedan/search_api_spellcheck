<?php

namespace Drupal\search_api_spellcheck\Plugin\views\area;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\search_api\Plugin\views\filter\SearchApiFulltext;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Drupal\views\Views;

/**
 * Provides an area for messages.
 *
 * @ingroup views_area_handlers
 *
 * @ViewsArea("search_api_spellcheck")
 */
class SpellCheck extends AreaPluginBase {

  const SPELLCHECK_CACHE_SUFFIX = ":spellcheck";
  const SPELLCHECK_CACHE_BIN = "data";
  /**
   * @var \Drupal\views\Plugin\views\cache\CachePluginBase
   */
  private $cache;

  /**
   * The available filters for the current view.
   *
   * @var array
   */
  private $filters;

  /**
   * The current query parameters.
   *
   * @var array
   */
  private $currentQuery;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['search_api_spellcheck_filter_name']['default'] = 'query';
    $options['search_api_spellcheck_hide_on_result']['default'] = TRUE;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['search_api_spellcheck_filter_name'] = [
      '#default_value' => $this->options['search_api_spellcheck_filter_name'] ?: 'query',
      '#title' => $this->t('Enter parameter name of text search filter'),
      '#type' => 'textfield',
    ];
    $form['search_api_spellcheck_hide_on_result'] = [
      '#default_value' => $this->options['search_api_spellcheck_hide_on_result'] ?? TRUE,
      '#title' => $this->t('Hide when the view has results.'),
      '#type' => 'checkbox',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preQuery() {
    /** @var \Drupal\search_api\Plugin\views\query\SearchApiQuery $query */
    $query = $this->query;
    $query->setOption('search_api_spellcheck', TRUE);
    parent::preQuery();
  }

  /**
   * @return mixed
   */
  protected function getCache() {
    if(!$this->cache) {
      if (!empty($this->live_preview)) {
        $this->cache = Views::pluginManager('cache')->createInstance('none');
      } else {
        $this->cache = $this->view->display_handler->getPlugin('cache');
      }
    }
    return $this->cache;
  }

  /**
   * Saves the solr response to the cache
   * @param $values
   */
  public function postExecute(&$values) {
    /** @var ResultSetInterface $result */
    $result = $this->query->getSearchApiResults();
    $response = $result->getExtraData('search_api_solr_response');
    $tags = $this->getCache()->getCacheTags();
    \Drupal::cache(self::SPELLCHECK_CACHE_BIN)->set($this->getCacheKey(), $response, Cache::PERMANENT, $tags);
    parent::postExecute($values);
  }

  /**
   * Returns a generated cache key (based on the views cache key)
   * @return string
   */
  public function getCacheKey() {
    $cache = $this->getCache();
    return $cache->generateResultsKey() . self::SPELLCHECK_CACHE_SUFFIX;
  }

  /**
   * Render the area.
   *
   * @param bool $empty
   *   (optional) Indicator if view result is empty or not. Defaults to FALSE.
   *
   * @return array
   *   In any case we need a valid Drupal render array to return.
   */
  public function render($empty = FALSE) {

    if ($this->options['search_api_spellcheck_hide_on_result'] == FALSE || ($this->options['search_api_spellcheck_hide_on_result'] && $empty)) {
      $cacheItem = \Drupal::cache(self::SPELLCHECK_CACHE_BIN)->get($this->getCacheKey());
      if ($extra_data = $cacheItem->data) {

        $filter_name = $this->options['search_api_spellcheck_filter_name'];

        // Check that we have suggestions.
        $keys = $this->view->getExposedInput()[$filter_name];
        $new_data = [];

        if (!empty($extra_data['spellcheck']['suggestions'])) {
          // Loop over the suggestions and print them as links.
          foreach ($extra_data['spellcheck']['suggestions'] as $key => $value) {
            if (is_string($value)) {
              $new_data[$key] = [
                'error' => $value,
                'suggestion' => $extra_data['spellcheck']['suggestions'][$key + 1]['suggestion'][0],
              ];
            }
          }
        }

        foreach ($new_data as $datum) {
          $keys = str_replace($datum['error'], $datum['suggestion'], $keys);
        }

        $build = [
          [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Did you mean: '),
          ],
          [
            '#type' => 'link',
            '#title' => str_replace('+', ' ', $keys),
            '#url' => Url::fromRoute('<current>', [], ['query' => ['keyword' => str_replace(' ', '+', $keys)]]),
          ],
          [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('?'),
          ],
        ];

        return $build;
      }
    }
    return [];
  }

  /**
   * Gets the current query parameters.
   *
   * @return array
   *   Key value of parameters.
   */
  protected function getCurrentQuery() {
    if (NULL === $this->currentQuery) {
      $this->currentQuery = \Drupal::request()->query->all();
    }
    return $this->currentQuery;
  }

  /**
   * Gets a list of filters.
   *
   * @return array
   *   The filters by key value.
   */
  protected function getFilters() {
    if (NULL === $this->filters) {
      $this->filters = [];
      $exposed_input = $this->view->getExposedInput();
      foreach ($this->view->filter as $key => $filter) {
        if ($filter instanceof SearchApiFulltext) {
          // The filter could be different then the key.
          if (!empty($filter->options['expose']['identifier'])) {
            $key = $filter->options['expose']['identifier'];
          }
          $this->filters[$key] = !empty($exposed_input[$key]) ? strtolower($exposed_input[$key]) : FALSE;
        }
      }
    }
    return $this->filters;
  }

  /**
   * Gets the matching filter for the suggestion.
   *
   * @param array $suggestion
   *   The suggestion array.
   *
   * @return bool|string
   *   False or the matching filter.
   */
  private function getFilterMatch(array $suggestion) {
    if ($index = array_search($suggestion[0], $this->getFilters(), TRUE)) {
      // @todo: Better validation.
      if (!empty($suggestion[1]['suggestion'][0])) {
        return [$index => $suggestion[1]['suggestion'][0]];
      }
    }
  }

}
