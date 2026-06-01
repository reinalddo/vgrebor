
    const monedas = {"1":{"tasa":1,"clave":"USD","mostrar_decimales":true},"2":{"tasa":750,"clave":"BS","mostrar_decimales":false}};
    let monedaActualId = "2";
    let monedaActualClave = "BS";
    let monedaActualTasa = 750.000000;
    let monedaActualMostrarDecimales = false;
    const monedaSelect = document.getElementById('moneda-select');
    const packCards = Array.from(document.querySelectorAll('.pack-card'));
    const normalizeCurrencyAmount = (amount, showDecimals) => {
      const numericAmount = Number(amount || 0);
      if (!Number.isFinite(numericAmount)) {
        return 0;
      }
      return showDecimals ? Number(numericAmount.toFixed(2)) : Math.trunc(numericAmount);
    };
    const formatCurrencyAmount = (amount, showDecimals) => {
      const normalized = normalizeCurrencyAmount(amount, showDecimals);
      return normalized.toLocaleString('en-US', {
        minimumFractionDigits: showDecimals ? 2 : 0,
        maximumFractionDigits: showDecimals ? 2 : 0,
      });
    };
    function setVisibleCurrency(currencyId, options = {}) {
      const nextId = String(currencyId || '').trim();
      if (nextId === '' || !monedas[nextId]) {
        return false;
      }

      monedaActualId = nextId;
      monedaActualClave = monedas[nextId].clave || 'USD';
      monedaActualTasa = parseFloat(monedas[nextId].tasa || '1');
      monedaActualMostrarDecimales = Boolean(monedas[nextId] && monedas[nextId].mostrar_decimales);

      if (options.syncSelect !== false && monedaSelect && String(monedaSelect.value) !== nextId) {
        monedaSelect.value = nextId;
      }

      updatePackPrices();

      if (activePack) {
        const selectedCard = packCards2.find((card) => card.classList.contains('neon-selected'));
        if (selectedCard) {
          activePack = buildPackStateFromCard(selectedCard);
          updateResumenCompra(activePack);
          renderPlayerFields(activePack);
        }
      } else {
        renderPlayerFields(null);
        updateResumenCompra(null);
      }

      if (options.resetCoupon !== false) {
        if (couponInput && couponInput.value.trim() !== '') {
          couponInput.value = '';
        }
        resetCouponState();
      }

      return true;
    }
    function updatePackPrices() {
      packCards.forEach(card => {
        const base = parseFloat(card.getAttribute('data-base'));
        const precio = normalizeCurrencyAmount(base * monedaActualTasa, monedaActualMostrarDecimales);
        card.querySelector('.precio-label').textContent = formatCurrencyAmount(precio, monedaActualMostrarDecimales);
        card.querySelector('.moneda-label').textContent = monedaActualClave;
        card.setAttribute('data-price-value', String(precio));
        card.setAttribute('data-show-decimals', monedaActualMostrarDecimales ? '1' : '0');
        card.setAttribute('data-moneda', monedaActualClave);
      });
    }
    updatePackPrices();
  
