/* ============================================================================
   MAP.JS - Entry point (tylko orchestrator)
   
   Ładuje moduły w odpowiedniej kolejności
   ============================================================================ */

async function initMap(appInstance) {
  const manager = MapManager.getInstance(appInstance);
  const map = await manager.init();
  return map;
}

window.initMap = initMap;