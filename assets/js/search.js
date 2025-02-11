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
                $results.html('<div class="mvb-error">' + wp.i18n.__('Please enter a search term', 'mvb') + '</div>');
                return;
            }

            $results.html('<div class="mvb-loading"><span class="spinner is-active"></span>' + 
                wp.i18n.__('Searching...', 'mvb') + '</div>');
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
                            (response.data.message || wp.i18n.__('No results found', 'mvb')) + '</div>');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('AJAX Error:', textStatus, errorThrown);
                    console.log('Response:', jqXHR.responseText);
                    $results.html('<div class="mvb-error">' + wp.i18n.__('Error occurred while searching', 'mvb') + '</div>');
                },
                complete: function() {
                    $searchButton.prop('disabled', false);
                }
            });
        }

        function renderGameCard(game) {
            const coverUrl = game.cover ? 
                game.cover.url.replace('t_thumb', 't_cover_big') : 
                'default-cover.jpg';
            
            const gameExists = game.exists ? 
                `<div class="notice notice-info">
                    <a href="${adminUrl}post.php?post=${game.existing_id}&action=edit">
                        ${__('Game already exists', 'mvb')}
                    </a>
                </div>` : 
                `<button type="button" class="button add-game" data-game='${JSON.stringify(game)}'>
                    ${__('Add Game', 'mvb')}
                </button>`;

            return `
                <div class="mvb-game-card">
                    <img class="mvb-game-cover" 
                        src="${coverUrl}" 
                        alt="${game.name}"
                    >
                    <div class="mvb-game-info">
                        <h3 class="mvb-game-title">${game.name}</h3>
                        ${game.release_date ? 
                            `<div class="mvb-game-meta">${game.release_date}</div>` : 
                            ''
                        }
                        ${game.summary ? 
                            `<div class="mvb-game-meta">${game.summary.substring(0, 100)}...</div>` : 
                            ''
                        }
                        ${gameExists}
                    </div>
                </div>
            `;
        }

        function displayResults(games) {
            if (!games.length) {
                $results.html('<div class="mvb-error">' + wp.i18n.__('No games found', 'mvb') + '</div>');
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
                    wp.i18n.__('Already Added', 'mvb') : 
                    wp.i18n.__('Add Game', 'mvb');
                
                const buttonAttr = game.exists ? 
                    'disabled' : 
                    `data-game='${JSON.stringify(game).replace(/'/g, "&#39;")}'`;
                
                return `
                    <div class="mvb-game-card">
                        <img class="mvb-game-cover" src="${coverUrl}" alt="${game.name}">
                        <div class="mvb-game-info">
                            <h3 class="mvb-game-title">${game.name}</h3>
                            ${game.release_date ? 
                                `<div class="mvb-game-meta">${game.release_date}</div>` : 
                                ''
                            }
                            ${game.summary ? 
                                `<div class="mvb-game-meta">${game.summary.substring(0, 100)}...</div>` : 
                                ''
                            }
                            <button type="button" 
                                class="${buttonClass}" 
                                ${buttonAttr}
                            >${buttonText}</button>
                        </div>
                    </div>
                `;
            }).join('');
            
            $results.html(html);

            // Add click handler for Add Game buttons
            $('.mvb-add-game').on('click', function() {
                const $button = $(this);
                const gameData = JSON.parse($button.attr('data-game'));
                
                $button.prop('disabled', true).text(wp.i18n.__('Adding...', 'mvb'));

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
                                .text(wp.i18n.__('Already Added', 'mvb'))
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
                                .text(wp.i18n.__('Add Game', 'mvb'))
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
                            .text(wp.i18n.__('Add Game', 'mvb'));
                        
                        $('<div class="notice notice-error"><p>' + 
                            wp.i18n.__('Error occurred while adding game:', 'mvb') + ' ' + errorThrown + '</p></div>')
                            .insertAfter($button);
                    }
                });
            });
        }

        function setLoading(isLoading) {
            const resultsContainer = document.getElementById('mvb-search-results');
            if (isLoading) {
                resultsContainer.innerHTML = `
                    <div class="mvb-loading">
                        <span class="spinner is-active"></span>
                        ${__('Searching...', 'mvb')}
                    </div>
                `;
            }
        }
    });
})(jQuery); 