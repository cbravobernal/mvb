(function($) {
    'use strict';

    $(document).ready(function() {
        const $searchInput = $('#mvb-game-search');
        const $searchButton = $('#mvb-search-button');
        const $results = $('#mvb-search-results');

        $searchButton.on('click', performSearch);
        $searchInput.on('keypress', function(e) {
            if (e.which === 13) {
                performSearch();
            }
        });

        function performSearch() {
            const searchTerm = $searchInput.val().trim();
            
            if (!searchTerm) {
                $results.html('<div class="mvb-error">Please enter a search term</div>');
                return;
            }

            $results.html('<div class="mvb-loading">Searching...</div>');
            $searchButton.prop('disabled', true);

            $.ajax({
                url: MVBSearch.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mvb_search_games',
                    nonce: MVBSearch.searchNonce,
                    search: searchTerm
                },
                success: function(response) {
                    if (response.success && response.data.games) {
                        displayResults(response.data.games);
                    } else {
                        $results.html('<div class="mvb-error">' + 
                            (response.data.message || 'No results found') + '</div>');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('AJAX Error:', textStatus, errorThrown);
                    console.log('Response:', jqXHR.responseText);
                    $results.html('<div class="mvb-error">Error occurred while searching</div>');
                },
                complete: function() {
                    $searchButton.prop('disabled', false);
                }
            });
        }

        function displayResults(games) {
            if (!games.length) {
                $results.html('<div class="mvb-error">No games found</div>');
                return;
            }

            const html = games.map(function(game) {
                const coverUrl = game.cover ? 
                    game.cover.url.replace('t_thumb', 't_cover_big') : 
                    'https://via.placeholder.com/264x374?text=No+Cover';

                const buttonClass = game.exists ? 
                    'button button-disabled' : 
                    'button button-primary mvb-add-game';

                const buttonText = game.exists ? 
                    'Already Added' : 
                    'Add Game';

                const buttonAttr = game.exists ? 
                    'disabled' : 
                    `data-game='${JSON.stringify(game).replace(/'/g, "&#39;")}'`;

                return `
                    <div class="mvb-game-card">
                        <img src="${coverUrl}" alt="${game.name}">
                        <h3>${game.name}</h3>
                        <button type="button" 
                            class="${buttonClass}" 
                            ${buttonAttr}
                        >${buttonText}</button>
                        ${game.exists ? 
                            `<div class="notice notice-info">
                                <p>Game already in database</p>
                            </div>` : 
                            ''}
                    </div>
                `;
            }).join('');

            $results.html(html);

            // Add click handler for Add Game buttons
            $('.mvb-add-game').on('click', function() {
                const $button = $(this);
                const gameData = JSON.parse($button.attr('data-game'));
                
                $button.prop('disabled', true).text('Adding...');

                $.ajax({
                    url: MVBSearch.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'mvb_add_game',
                        nonce: MVBSearch.addNonce,
                        game: JSON.stringify(gameData)
                    },
                    success: function(response) {
                        if (response.success) {
                            $button
                                .text('Already Added')
                                .addClass('button-disabled')
                                .removeClass('button-primary mvb-add-game')
                                .removeAttr('data-game');
                            
                            $('<div class="notice notice-success"><p>' + response.data.message + '</p></div>')
                                .insertAfter($button)
                                .delay(3000)
                                .fadeOut();
                        } else {
                            $button
                                .prop('disabled', false)
                                .text('Add Game')
                                .removeClass('button-disabled');
                            
                            $('<div class="notice notice-error"><p>' + response.data.message + '</p></div>')
                                .insertAfter($button);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.error('AJAX Error:', textStatus, errorThrown);
                        console.log('Game Data:', gameData);
                        console.log('Response:', jqXHR.responseText);
                        $button
                            .prop('disabled', false)
                            .text('Add Game');
                        
                        $('<div class="notice notice-error"><p>Error occurred while adding game: ' + errorThrown + '</p></div>')
                            .insertAfter($button);
                    }
                });
            });
        }
    });
})(jQuery); 