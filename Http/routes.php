<?php

// Settings routes (admin only)
Route::group(['middleware' => ['web', 'auth', 'roles'], 'roles' => ['admin'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\KnowledgeBaseApiModule\Http\Controllers'], function () {
    Route::get('/app-settings/knowledge-base-api', ['uses' => 'SettingsController@index'])->name('knowledgebaseapimodule.settings');
    Route::post('/app-settings/knowledge-base-api', ['uses' => 'SettingsController@save'])->name('knowledgebaseapimodule.settings.save');
    
    // Direct access route for debugging
    Route::get('/kb-api-settings', ['uses' => 'SettingsController@index'])->name('knowledgebaseapimodule.direct-settings');
    
    // Analytics dashboard
    Route::get('/app-settings/kb-analytics', ['uses' => 'AnalyticsController@index'])->name('knowledgebaseapimodule.analytics');
});

// API routes (protected by token middleware)
Route::group(['middleware' => ['knowledgebase.api.token'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\KnowledgeBaseApiModule\Http\Controllers'], function () {
    Route::get('/api/knowledgebase/{mailboxId}/categories', ['uses' => 'KnowledgeBaseApiController@get', 'laroute' => false])->name('knowledgebase.index');
    Route::get('/api/knowledgebase/{mailboxId}/categories/{categoryId}', ['uses' => 'KnowledgeBaseApiController@category', 'laroute' => false])->name('knowledgebase.category');
    Route::get('/api/knowledgebase/{mailboxId}/categories/{categoryId}/articles/{articleId}', ['uses' => 'KnowledgeBaseApiController@article', 'laroute' => false])->name('knowledgebase.article');
    Route::get('/api/knowledgebase/{mailboxId}/search', ['uses' => 'KnowledgeBaseApiController@search', 'laroute' => false])->name('knowledgebase.search');
    Route::get('/api/knowledgebase/{mailboxId}/popular', ['uses' => 'KnowledgeBaseApiController@popular', 'laroute' => false])->name('knowledgebase.popular');
    Route::get('/api/knowledgebase/{mailboxId}/export', ['uses' => 'KnowledgeBaseApiController@export', 'laroute' => false])->name('knowledgebase.export');
    Route::get('/api/knowledgebase/{mailboxId}/languages', ['uses' => 'KnowledgeBaseApiController@languages', 'laroute' => false])->name('knowledgebase.languages');
});
