<?php

namespace Modules\KnowledgeBaseApiModule\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Modules\KnowledgeBase\Entities\KbCategory;
use Modules\KnowledgeBase\Entities\KbArticle;
use Modules\KnowledgeBase\Entities\KbArticleKbCategory;
use Modules\KnowledgeBaseApiModule\Models\KbCategoryViews;
use Modules\KnowledgeBaseApiModule\Models\KbArticleViews;
use Modules\KnowledgeBaseApiModule\Models\KbSearchQuery;

class KnowledgeBaseApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        // Token validation is handled by middleware
    }

    /**
     * Get all categories for a mailbox.
     *
     * @param Request $request
     * @param int $mailboxId
     * @return \Illuminate\Http\JsonResponse
     */
    public function get(Request $request, $mailboxId)
    {
        try {
            $mailbox = Mailbox::findOrFail($mailboxId);
            if ($mailbox === null) {
                return Response::json(['error' => 'Mailbox not found'], 404);
            }

            $locale = $this->resolveLocale($request, $mailbox);
            $filterLocale = $request->input('lang') ?? $request->input('locale');
            $flat = (bool) $request->input('flat', false);

            $categories = \KbCategory::getTree($mailbox->id);

            if ($flat) {
                $result = $this->buildCategoryFlat($categories, $locale, $mailbox->id, $filterLocale);
            } else {
                $result = $this->buildCategoryTree($categories, $locale, $mailbox->id, $filterLocale);
            }

            return Response::json([
                'mailbox_id' => $mailbox->id,
                'name' => $mailbox->name,
                'categories' => $result,
            ], 200);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get a specific category with its articles.
     *
     * @param Request $request
     * @param int $mailboxId
     * @param int $categoryId
     * @return \Illuminate\Http\JsonResponse
     */
    public function category(Request $request, $mailboxId, $categoryId)
    {
        try {
            $mailbox = Mailbox::findOrFail($mailboxId);
            if ($mailbox === null) {
                return Response::json(['error' => 'Mailbox not found'], 404);
            }

            $category = KbCategory::findOrFail($categoryId);
            if (!$category->checkVisibility()) {
                $category = null;
            }
            if ($category === null) {
                return Response::json(['error' => 'Category not found or not visible'], 404);
            }

            // Track category view
            KbCategoryViews::incrementViews($categoryId, $mailboxId);

            $locale = $this->resolveLocale($request, $mailbox);
            $filterLocale = $request->input('lang') ?? $request->input('locale');

            // Get articles for this category
            $articles = [];
            $sortedArticles = $category->getArticlesSorted(true);
            foreach ($sortedArticles as $a) {
                $a->setLocale($locale);

                if ($filterLocale && $a->locale !== $filterLocale) {
                    continue;
                }

                $articles[] = (object)[
                    'id'         => $a->id,
                    'title'      => $a->getAttributeInLocale('title', $locale),
                    'text'       => $a->getAttributeInLocale('text', $locale),
                    'locale'     => $a->locale,
                    'url'        => $this->buildArticleUrl($mailbox->id, $category->id, $a->id),
                    'client_url' => $this->buildClientArticleUrl($mailbox->id, $category->id, $a->id),
                ];
            }

            // Get sub-categories
            $subCategories = $category->getSubCategories();
            $children = [];
            if ($subCategories && count($subCategories) > 0) {
                foreach ($subCategories as $sub) {
                    if (!$sub->checkVisibility()) {
                        continue;
                    }
                    $subArticleCount = 0;
                    if (method_exists($sub, 'getArticlesSorted')) {
                        $subArticles = $sub->getArticlesSorted(true);
                        if ($filterLocale) {
                            $subArticles = array_filter($subArticles, fn($a) => $a->locale === $filterLocale);
                        }
                        $subArticleCount = count($subArticles);
                    }
                    $children[] = [
                        'id'            => $sub->id,
                        'name'          => $sub->getAttributeInLocale('name', $locale),
                        'description'   => $sub->getAttributeInLocale('description', $locale),
                        'url'           => $this->buildCategoryUrl($mailbox->id, $sub->id),
                        'client_url'    => $this->buildClientCategoryUrl($mailbox->id, $sub->id),
                        'article_count' => $subArticleCount,
                    ];
                }
            }

            // Generate category URLs
            $categoryUrl = $this->buildCategoryUrl($mailbox->id, $category->id);
            $clientCategoryUrl = $this->buildClientCategoryUrl($mailbox->id, $category->id);

            return Response::json([
                'mailbox_id' => $mailbox->id,
                'name' => $mailbox->name,
                'category' => [
                    'id'            => $category->id,
                    'name'          => $category->getAttributeInLocale('name', $locale),
                    'description'   => $category->getAttributeInLocale('description', $locale),
                    'url'           => $categoryUrl,
                    'client_url'    => $clientCategoryUrl,
                    'children'      => $children,
                ],
                'articles' => $articles,
            ], 200);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Search for articles by keyword.
     *
     * @param Request $request
     * @param int $mailboxId
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request, $mailboxId)
    {
        try {
            $mailbox = Mailbox::findOrFail($mailboxId);
            if ($mailbox === null) {
                return Response::json(['error' => 'Mailbox not found'], 404);
            }

            $keyword = $request->input('q');
            if (empty($keyword)) {
                return Response::json(['error' => 'Search keyword is required'], 400);
            }

            $locale = $this->resolveLocale($request, $mailbox);

            // Only filter by locale if explicitly requested
            $filterLocale = $request->input('lang') ?? $request->input('locale');

            // Convert keyword to lowercase for case-insensitive search
            $keyword = mb_strtolower($keyword);

            // Search in published articles only, using case-insensitive search
            $articles = KbArticle::where('mailbox_id', $mailbox->id)
                ->where(function($query) use ($keyword) {
                    $query->whereRaw('LOWER(title) LIKE ?', ['%'.$keyword.'%'])
                          ->orWhereRaw('LOWER(text) LIKE ?', ['%'.$keyword.'%']);
                })
                ->where('status', KbArticle::STATUS_PUBLISHED)
                ->when($filterLocale, function($q) use ($filterLocale) {
                    $q->where('locale', $filterLocale);
                })
                ->get();

            $results = [];
            foreach ($articles as $article) {
                // Get categories for this article and check if at least one is visible
                $hasVisibleCategory = false;
                $categories = [];
                
                foreach ($article->categories as $category) {
                    // Only include visible categories
                    if (method_exists($category, 'checkVisibility') && $category->checkVisibility()) {
                        $hasVisibleCategory = true;
                        $categories[] = [
                            'id' => $category->id,
                            'name' => $category->getAttributeInLocale('name', $locale)
                        ];
                    }
                }
                
                // Only show articles with at least one visible category
                if ($hasVisibleCategory) {
                    // Get the first visible category ID for URL construction
                    $firstCategoryId = $categories[0]['id'];
                    
                    // Use the helper method to build the URL
                    $articleUrl = $this->buildArticleUrl($mailbox->id, $firstCategoryId, $article->id);
                    $clientArticleUrl = $this->buildClientArticleUrl($mailbox->id, $firstCategoryId, $article->id);
                    
                    $results[] = [
                        'id'         => $article->id,
                        'title'      => $article->getAttributeInLocale('title', $locale),
                        'text'       => $article->getAttributeInLocale('text', $locale),
                        'locale'     => $article->locale,
                        'categories' => $categories,
                        'url'        => $articleUrl,
                        'client_url' => $clientArticleUrl,
                    ];
                }
            }

            // Track this search query
            KbSearchQuery::trackSearch($mailbox->id, $keyword, count($results), $locale);

            return Response::json([
                'mailbox_id' => $mailbox->id,
                'keyword' => $keyword,
                'count' => count($results),
                'results' => $results
            ], 200);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get a specific article by its ID within a category.
     *
     * @param Request $request
     * @param int $mailboxId
     * @param int $categoryId
     * @param int $articleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function article(Request $request, $mailboxId, $categoryId, $articleId)
    {
        try {
            // Check if mailbox exists
            $mailbox = Mailbox::findOrFail($mailboxId);
            if ($mailbox === null) {
                return Response::json(['error' => 'Mailbox not found'], 404);
            }

            // Check if category exists and is visible
            $category = KbCategory::findOrFail($categoryId);
            if (!$category->checkVisibility()) {
                return Response::json(['error' => 'Category not found or not visible'], 404);
            }

            // Get the article
            $article = KbArticle::where('id', $articleId)
                ->where('mailbox_id', $mailboxId)
                ->where('status', KbArticle::STATUS_PUBLISHED)
                ->first();

            if (!$article) {
                return Response::json(['error' => 'Article not found or not published'], 404);
            }

            // Check if article belongs to the specified category
            $belongs = false;
            foreach ($article->categories as $cat) {
                if ($cat->id == $categoryId) {
                    $belongs = true;
                    break;
                }
            }

            if (!$belongs) {
                return Response::json(['error' => 'Article does not belong to the specified category'], 404);
            }

            // Track article view
            KbArticleViews::incrementViews($articleId, $categoryId, $mailboxId);

            // Get locale
            $locale = $this->resolveLocale($request, $mailbox);
            $article->setLocale($locale);

            // Use the helper method to build the URL
            $articleUrl = $this->buildArticleUrl($mailbox->id, $category->id, $article->id);
            $clientArticleUrl = $this->buildClientArticleUrl($mailbox->id, $category->id, $article->id);

            // Generate category URLs
            $categoryUrl = $this->buildCategoryUrl($mailbox->id, $category->id);
            $clientCategoryUrl = $this->buildClientCategoryUrl($mailbox->id, $category->id);

            return Response::json([
                'mailbox_id' => $mailbox->id,
                'mailbox_name' => $mailbox->name,
                'category' => [
                    'id' => $category->id,
                    'name' => $category->getAttributeInLocale('name', $locale),
                    'url' => $categoryUrl,
                    'client_url' => $clientCategoryUrl
                ],
                'article' => [
                    'id'         => $article->id,
                    'title'      => $article->getAttributeInLocale('title', $locale),
                    'text'       => $article->getAttributeInLocale('text', $locale),
                    'locale'     => $article->locale,
                    'url'        => $articleUrl,
                    'client_url' => $clientArticleUrl,
                ]
            ], 200);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve locale from request, preferring `lang` over `locale`, falling back to mailbox default.
     *
     * @param Request $request
     * @param \App\Mailbox $mailbox
     * @return string
     */
    private function resolveLocale(Request $request, $mailbox): string
    {
        return $request->input('lang')
            ?? $request->input('locale')
            ?? \Kb::defaultLocale($mailbox);
    }

    /**
     * Recursively build a nested category tree.
     * Uses the ->categories property populated by KbCategory::getTree().
     *
     * @param array $categories
     * @param string $locale
     * @param int $mailboxId
     * @param string|null $filterLocale
     * @return array
     */
    private function buildCategoryTree(array $categories, string $locale, int $mailboxId, ?string $filterLocale = null): array
    {
        $tree = [];
        foreach ($categories as $c) {
            if (!$c->checkVisibility()) {
                continue;
            }

            $children = [];
            if (!empty($c->categories)) {
                $children = $this->buildCategoryTree(
                    is_array($c->categories) ? $c->categories : $c->categories->all(),
                    $locale, $mailboxId, $filterLocale
                );
            }

            $articleCount = 0;
            if (method_exists($c, 'getArticlesSorted')) {
                $articles = $c->getArticlesSorted(true);
                if ($filterLocale) {
                    $articles = array_filter($articles, fn($a) => $a->locale === $filterLocale);
                }
                $articleCount = count($articles);
            }

            // Skip categories with no matching articles and no matching children
            if ($filterLocale && $articleCount === 0 && empty($children)) {
                continue;
            }

            $tree[] = [
                'id'            => $c->id,
                'name'          => $c->getAttributeInLocale('name', $locale),
                'description'   => $c->getAttributeInLocale('description', $locale),
                'url'           => $this->buildCategoryUrl($mailboxId, $c->id),
                'client_url'    => $this->buildClientCategoryUrl($mailboxId, $c->id),
                'article_count' => $articleCount,
                'children'      => $children,
            ];
        }
        return $tree;
    }

    /**
     * Build a flat (non-nested) list of visible categories.
     * Recursively flattens the tree from KbCategory::getTree().
     *
     * @param array $categories
     * @param string $locale
     * @param int $mailboxId
     * @param string|null $filterLocale
     * @return array
     */
    private function buildCategoryFlat(array $categories, string $locale, int $mailboxId, ?string $filterLocale = null): array
    {
        $flat = [];
        foreach ($categories as $c) {
            if (!$c->checkVisibility()) {
                continue;
            }
            $articleCount = 0;
            if (method_exists($c, 'getArticlesSorted')) {
                $articles = $c->getArticlesSorted(true);
                if ($filterLocale) {
                    $articles = array_filter($articles, fn($a) => $a->locale === $filterLocale);
                }
                $articleCount = count($articles);
            }
            if ($filterLocale && $articleCount === 0) {
                continue;
            }
            $flat[] = [
                'id'            => $c->id,
                'parent_id'     => $c->kb_category_id ? (int)$c->kb_category_id : null,
                'name'          => $c->getAttributeInLocale('name', $locale),
                'description'   => $c->getAttributeInLocale('description', $locale),
                'url'           => $this->buildCategoryUrl($mailboxId, $c->id),
                'client_url'    => $this->buildClientCategoryUrl($mailboxId, $c->id),
                'article_count' => $articleCount,
            ];
            // Recurse into sub-categories
            if (!empty($c->categories)) {
                $subFlat = $this->buildCategoryFlat(
                    is_array($c->categories) ? $c->categories : $c->categories->all(),
                    $locale, $mailboxId, $filterLocale
                );
                $flat = array_merge($flat, $subFlat);
            }
        }
        return $flat;
    }

    /**
     * Get the list of distinct locales available for published articles in a mailbox.
     *
     * @param Request $request
     * @param int $mailboxId
     * @return \Illuminate\Http\JsonResponse
     */
    public function languages(Request $request, $mailboxId)
    {
        try {
            $mailbox = Mailbox::findOrFail($mailboxId);
            $locales = KbArticle::where('mailbox_id', $mailbox->id)
                ->where('status', KbArticle::STATUS_PUBLISHED)
                ->whereNotNull('locale')
                ->distinct()
                ->pluck('locale')
                ->filter()
                ->values()
                ->toArray();
            return Response::json([
                'mailbox_id' => $mailbox->id,
                'languages'  => $locales,
            ], 200);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Build article URL based on settings.
     *
     * @param int $mailboxId
     * @param int $categoryId
     * @param int $articleId
     * @return string
     */
    private function buildArticleUrl($mailboxId, $categoryId, $articleId)
    {
        // Check if we have a custom URL template
        $customUrlTemplate = \App\Option::get('knowledgebase_api_custom_url');
        
        if (!empty($customUrlTemplate)) {
            // Replace placeholders with actual values
            return str_replace(
                ['[mailbox]', '[category]', '[article]'],
                [$mailboxId, $categoryId, $articleId],
                $customUrlTemplate
            );
        }
        
        // Default to standard FreeScout KB URL
        return url('/kb/article/'.$articleId);
    }

    /**
     * Build client-side article URL based on settings.
     *
     * @param int $mailboxId
     * @param int $categoryId
     * @param int $articleId
     * @return string|null
     */
    private function buildClientArticleUrl($mailboxId, $categoryId, $articleId)
    {
        // Check if we have a client URL template
        $clientUrlTemplate = \App\Option::get('knowledgebase_api_client_url');
        
        if (!empty($clientUrlTemplate)) {
            // Replace placeholders with actual values
            return str_replace(
                ['[mailbox]', '[category]', '[article]'],
                [$mailboxId, $categoryId, $articleId],
                $clientUrlTemplate
            );
        }
        
        // Return null if no client URL template is set
        return null;
    }

    /**
     * Build category URL based on settings.
     *
     * @param int $mailboxId
     * @param int $categoryId
     * @return string
     */
    private function buildCategoryUrl($mailboxId, $categoryId)
    {
        // Check if we have a custom URL template
        $customUrlTemplate = \App\Option::get('knowledgebase_api_custom_url');
        
        if (!empty($customUrlTemplate)) {
            // Replace placeholders with actual values
            // Remove [article] placeholder if present (since this is for categories)
            $categoryTemplate = str_replace('[article]', '', $customUrlTemplate);
            // Trim any trailing slashes that might have resulted from removing the article
            $categoryTemplate = rtrim($categoryTemplate, '/');
            
            return str_replace(
                ['[mailbox]', '[category]'],
                [$mailboxId, $categoryId],
                $categoryTemplate
            );
        }
        
        // Default to standard FreeScout KB URL for category
        return url('/kb/category/'.$categoryId);
    }

    /**
     * Build client-side category URL based on settings.
     *
     * @param int $mailboxId
     * @param int $categoryId
     * @return string|null
     */
    private function buildClientCategoryUrl($mailboxId, $categoryId)
    {
        // Check if we have a client URL template
        $clientUrlTemplate = \App\Option::get('knowledgebase_api_client_url');
        
        if (!empty($clientUrlTemplate)) {
            // Replace placeholders with actual values
            // Remove [article] placeholder if present (since this is for categories)
            $categoryTemplate = str_replace('[article]', '', $clientUrlTemplate);
            // Trim any trailing slashes or ampersands that might have resulted from removing the article
            $categoryTemplate = rtrim($categoryTemplate, '/&');
            
            return str_replace(
                ['[mailbox]', '[category]'],
                [$mailboxId, $categoryId],
                $categoryTemplate
            );
        }
        
        // Return null if no client URL template is set
        return null;
    }

    /**
     * Get popular articles and categories based on view counts.
     *
     * @param Request $request
     * @param int $mailboxId
     * @return \Illuminate\Http\JsonResponse
     */
    public function popular(Request $request, $mailboxId)
    {
        try {
            $mailbox = Mailbox::findOrFail($mailboxId);
            if ($mailbox === null) {
                return Response::json(['error' => 'Mailbox not found'], 404);
            }

            $limit = (int) $request->input('limit', 5);
            $type = $request->input('type', 'all');
            $locale = $this->resolveLocale($request, $mailbox);
            
            $response = [
                'mailbox_id' => $mailbox->id,
                'name' => $mailbox->name,
            ];
            
            // Get popular categories if requested
            if ($type === 'all' || $type === 'categories') {
                $popularCategories = [];
                
                // Get top viewed categories
                $topCategories = \Modules\KnowledgeBaseApiModule\Models\KbCategoryViews::where('mailbox_id', $mailbox->id)
                    ->orderBy('view_count', 'desc')
                    ->limit($limit)
                    ->get();
                
                foreach ($topCategories as $categoryView) {
                    $category = KbCategory::find($categoryView->category_id);
                    if ($category && $category->checkVisibility()) {
                        // Generate URL for the category
                        $categoryUrl = $this->buildCategoryUrl($mailbox->id, $category->id);
                        $clientCategoryUrl = $this->buildClientCategoryUrl($mailbox->id, $category->id);
                        
                        $popularCategories[] = [
                            'id' => $category->id,
                            'name' => $category->getAttributeInLocale('name', $locale),
                            'description' => $category->getAttributeInLocale('description', $locale),
                            'view_count' => $categoryView->view_count,
                            'url' => $categoryUrl,
                            'client_url' => $clientCategoryUrl
                        ];
                    }
                }
                
                $response['popular_categories'] = $popularCategories;
            }
            
            // Get popular articles if requested
            if ($type === 'all' || $type === 'articles') {
                $popularArticles = [];
                
                // Get top viewed articles
                $topArticles = \Modules\KnowledgeBaseApiModule\Models\KbArticleViews::where('mailbox_id', $mailbox->id)
                    ->orderBy('view_count', 'desc')
                    ->limit($limit)
                    ->get();
                
                foreach ($topArticles as $articleView) {
                    $article = KbArticle::find($articleView->article_id);
                    $category = KbCategory::find($articleView->category_id);
                    
                    if ($article && $article->status == KbArticle::STATUS_PUBLISHED && $category && $category->checkVisibility()) {
                        // Generate URL for the article
                        $articleUrl = $this->buildArticleUrl($mailbox->id, $category->id, $article->id);
                        $clientArticleUrl = $this->buildClientArticleUrl($mailbox->id, $category->id, $article->id);
                        
                        $popularArticles[] = [
                            'id' => $article->id,
                            'title' => $article->getAttributeInLocale('title', $locale),
                            'view_count' => $articleView->view_count,
                            'url' => $articleUrl,
                            'client_url' => $clientArticleUrl,
                            'category' => [
                                'id' => $category->id,
                                'name' => $category->getAttributeInLocale('name', $locale)
                            ]
                        ];
                    }
                }
                
                $response['popular_articles'] = $popularArticles;
            }
            
            return Response::json($response, 200);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export all KB content for AI training purposes.
     *
     * @param Request $request
     * @param int $mailboxId
     * @return \Illuminate\Http\JsonResponse
     */
    public function export(Request $request, $mailboxId)
    {
        try {
            $mailbox = Mailbox::findOrFail($mailboxId);
            if ($mailbox === null) {
                return Response::json(['error' => 'Mailbox not found'], 404);
            }

            $locale = $this->resolveLocale($request, $mailbox);
            $includeHidden = $request->input('include_hidden', false);
            
            // Get all categories for this mailbox
            $categories = KbCategory::where('mailbox_id', $mailbox->id)
                ->orderBy('id')
                ->get();
                
            $exportData = [
                'mailbox_id' => $mailbox->id,
                'name' => $mailbox->name,
                'categories' => [],
                'generated_at' => now()->toIso8601String(),
            ];
            
            // Process each category
            foreach ($categories as $category) {
                // Skip hidden categories if requested
                if (!$includeHidden && !$category->checkVisibility()) {
                    continue;
                }
                
                $categoryData = [
                    'id' => $category->id,
                    'name' => $category->getAttributeInLocale('name', $locale),
                    'description' => $category->getAttributeInLocale('description', $locale),
                    'url' => $this->buildCategoryUrl($mailbox->id, $category->id),
                    'client_url' => $this->buildClientCategoryUrl($mailbox->id, $category->id),
                    'articles' => []
                ];
                
                // Get articles for this category
                $articles = [];
                if (method_exists($category, 'getArticlesSorted')) {
                    // Get all articles (published only if not including hidden)
                    $articles = $category->getArticlesSorted(!$includeHidden);
                }
                
                // Process each article
                foreach ($articles as $article) {
                    $article->setLocale($locale);
                    
                    $articleData = [
                        'id'         => $article->id,
                        'title'      => $article->getAttributeInLocale('title', $locale),
                        'text'       => $article->getAttributeInLocale('text', $locale),
                        'locale'     => $article->locale,
                        'status'     => $article->status,
                        'url'        => $this->buildArticleUrl($mailbox->id, $category->id, $article->id),
                        'client_url' => $this->buildClientArticleUrl($mailbox->id, $category->id, $article->id),
                    ];
                    
                    $categoryData['articles'][] = $articleData;
                }
                
                $exportData['categories'][] = $categoryData;
            }
            
            return Response::json($exportData, 200);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }
}
