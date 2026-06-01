
    window.__TVG_BASE_PATH = "";
    window.__TVG_API_PEDIDOS = "/api/pedidos.php";
    window.__TVG_API_ACCOUNT = "/api/account.php";
    window.__TVG_SEARCH_ENDPOINT = "/api/search_catalog.php";
    window.__TVG_SEARCH_RESULTS = "/buscar";
    document.addEventListener('DOMContentLoaded', function() {
      var publicBackgroundVideo = document.querySelector('[data-site-background-video]');
      if (publicBackgroundVideo) {
        var desiredVolume = Number(publicBackgroundVideo.getAttribute('data-volume') || '0');
        var soundEnabled = publicBackgroundVideo.getAttribute('data-sound-enabled') === '1';
        var startBackgroundPlayback = function() {
          var playPromise = publicBackgroundVideo.play();
          if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(function() {});
          }
        };

        publicBackgroundVideo.volume = Math.max(0, Math.min(1, desiredVolume));
        publicBackgroundVideo.muted = !soundEnabled;
        startBackgroundPlayback();

        if (soundEnabled) {
          var unlockBackgroundAudio = function() {
            publicBackgroundVideo.muted = false;
            publicBackgroundVideo.volume = Math.max(0, Math.min(1, desiredVolume));
            startBackgroundPlayback();
          };

          document.addEventListener('click', unlockBackgroundAudio, { once: true });
          document.addEventListener('touchstart', unlockBackgroundAudio, { once: true });
          document.addEventListener('keydown', unlockBackgroundAudio, { once: true });
        }
      }

      var menuToggle = document.getElementById('menu-toggle');
      var menuPanel = document.getElementById('menu-panel');
      var menuOverlay = document.getElementById('menu-overlay');
      var menuClose = document.getElementById('menu-close');
      if (menuToggle && menuPanel && menuOverlay) {
        menuToggle.addEventListener('click', function() {
          menuPanel.classList.remove('d-none');
          menuOverlay.classList.remove('d-none');
        });
        menuOverlay.addEventListener('click', function() {
          menuPanel.classList.add('d-none');
          menuOverlay.classList.add('d-none');
        });
      }
      if (menuClose) {
        menuClose.addEventListener('click', function() {
          menuPanel.classList.add('d-none');
          menuOverlay.classList.add('d-none');
        });
      }

      var siteTopbar = document.querySelector('[data-site-topbar="1"]');
      if (siteTopbar) {
        var updateTopbarOpacity = function() {
          var scrollY = window.scrollY || window.pageYOffset || 0;
          var progress = Math.min(1, scrollY / 260);
          var opacity = (0.96 - (progress * 0.54)).toFixed(3);
          siteTopbar.style.setProperty('--site-topbar-opacity', opacity);
        };

        updateTopbarOpacity();
        window.addEventListener('scroll', updateTopbarOpacity, { passive: true });
      }

      var searchRoot = document.querySelector('[data-public-search]');
      if (searchRoot) {
        var searchForm = searchRoot.querySelector('[data-public-search-form]');
        var searchInput = searchRoot.querySelector('[data-public-search-input]');
        var searchDropdown = searchRoot.querySelector('[data-public-search-results]');
        var searchList = searchRoot.querySelector('[data-public-search-list]');
        var searchStatus = searchRoot.querySelector('[data-public-search-status]');
        var fetchTimer = 0;
        var activeIndex = -1;
        var searchItems = [];
        var searchController = null;

        var searchEscapeHtml = function(value) {
          return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
        };

        var hideSearchDropdown = function() {
          if (searchDropdown) {
            searchDropdown.classList.remove('is-visible');
          }
          activeIndex = -1;
        };

        var setSearchStatus = function(message) {
          if (searchStatus) {
            searchStatus.textContent = message;
          }
        };

        var navigateToSearchItem = function(index) {
          var item = searchItems[index] || null;
          if (!item || !item.url) {
            return;
          }
          window.location.href = item.url;
        };

        var highlightSearchItem = function(index) {
          activeIndex = index;
          if (!searchList) {
            return;
          }
          Array.prototype.forEach.call(searchList.querySelectorAll('[data-search-item-index]'), function(node) {
            var itemIndex = Number(node.getAttribute('data-search-item-index') || '-1');
            node.classList.toggle('is-active', itemIndex === activeIndex);
          });
        };

        var renderSearchItems = function(items) {
          searchItems = Array.isArray(items) ? items : [];
          activeIndex = -1;
          if (!searchList || !searchDropdown) {
            return;
          }
          if (!searchItems.length) {
            searchList.innerHTML = '';
            setSearchStatus('No hay coincidencias con ese texto.');
            searchDropdown.classList.add('is-visible');
            return;
          }

          setSearchStatus('Selecciona un resultado o presiona Enter para ver la búsqueda completa.');
          searchList.innerHTML = searchItems.map(function(item, index) {
            var subtitle = item.type === 'package' && item.game_name
              ? 'Paquete de ' + item.game_name
              : 'Juego disponible';
            var price = item.price_label ? '<span class="site-topbar-search-price">' + searchEscapeHtml(item.price_label) + '</span>' : '';
            var image = item.image_url
              ? '<img src="' + searchEscapeHtml(item.image_url) + '" alt="' + searchEscapeHtml(item.name || '') + '">'
              : '<div class="w-100 h-100 d-flex align-items-center justify-content-center fw-bold text-info">' + (item.type === 'package' ? 'PK' : 'JG') + '</div>';
            return '' +
              '<button type="button" class="site-topbar-search-item" data-search-item-index="' + index + '" data-search-item-href="' + searchEscapeHtml(item.url || '') + '">' +
                '<span class="site-topbar-search-thumb">' + image + '</span>' +
                '<span class="site-topbar-search-meta">' +
                  '<span class="site-topbar-search-badge">' + searchEscapeHtml(item.badge || '') + '</span>' +
                  '<span class="site-topbar-search-title">' + searchEscapeHtml(item.name || '') + '</span>' +
                  '<span class="site-topbar-search-subtitle">' + searchEscapeHtml(subtitle) + '</span>' +
                  price +
                '</span>' +
              '</button>';
          }).join('');

          Array.prototype.forEach.call(searchList.querySelectorAll('[data-search-item-index]'), function(button) {
            button.addEventListener('click', function() {
              var index = Number(button.getAttribute('data-search-item-index') || '-1');
              navigateToSearchItem(index);
            });
          });
          searchDropdown.classList.add('is-visible');
        };

        var requestSearchItems = function(term) {
          if (!searchList || !searchDropdown) {
            return;
          }
          if (term.length < 2) {
            searchList.innerHTML = '';
            setSearchStatus('Escribe al menos 2 letras para buscar juegos o paquetes.');
            hideSearchDropdown();
            return;
          }

          if (searchController && typeof searchController.abort === 'function') {
            searchController.abort();
          }
          searchController = typeof AbortController !== 'undefined' ? new AbortController() : null;
          setSearchStatus('Buscando coincidencias...');
          searchDropdown.classList.add('is-visible');

          var endpointUrl = new URL(window.__TVG_SEARCH_ENDPOINT || '', window.location.origin);
          endpointUrl.searchParams.set('q', term);
          endpointUrl.searchParams.set('limit', '8');

          fetch(endpointUrl.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: searchController ? searchController.signal : undefined
          })
            .then(function(response) { return response.json(); })
            .then(function(payload) {
              renderSearchItems(payload.items || []);
            })
            .catch(function(error) {
              if (error && error.name === 'AbortError') {
                return;
              }
              searchList.innerHTML = '';
              setSearchStatus('No se pudo cargar la búsqueda en este momento.');
              searchDropdown.classList.add('is-visible');
            });
        };

        if (searchInput) {
          searchInput.addEventListener('input', function() {
            var term = searchInput.value.trim();
            window.clearTimeout(fetchTimer);
            fetchTimer = window.setTimeout(function() {
              requestSearchItems(term);
            }, 180);
          });

          searchInput.addEventListener('focus', function() {
            if (searchInput.value.trim().length >= 2 && searchItems.length) {
              searchDropdown.classList.add('is-visible');
            }
          });

          searchInput.addEventListener('keydown', function(event) {
            if (!searchItems.length) {
              return;
            }

            if (event.key === 'ArrowDown') {
              event.preventDefault();
              highlightSearchItem((activeIndex + 1) % searchItems.length);
            } else if (event.key === 'ArrowUp') {
              event.preventDefault();
              highlightSearchItem(activeIndex <= 0 ? searchItems.length - 1 : activeIndex - 1);
            } else if (event.key === 'Enter' && activeIndex >= 0) {
              event.preventDefault();
              navigateToSearchItem(activeIndex);
            } else if (event.key === 'Escape') {
              hideSearchDropdown();
            }
          });
        }

        if (searchForm) {
          searchForm.addEventListener('submit', function() {
            hideSearchDropdown();
          });
        }

        document.addEventListener('click', function(event) {
          if (!searchRoot.contains(event.target)) {
            hideSearchDropdown();
          }
        });
      }
    });
  
