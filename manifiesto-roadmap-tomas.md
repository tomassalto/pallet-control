# Manifiesto Roadmap — De conformista a profesional

**Tomás Salto — Neuquén Capital, Argentina**
**Período: Agosto 2026 → Diciembre 2026 (4 meses)**
**Punto de decisión: inscripción Licenciatura en Ciencias de la Computación (UNCo) para 2027**

---

## 0. El diagnóstico honesto

**Situación actual:**
- Técnico Universitario en Desarrollo Web (UNCo)
- Experiencia real: Front-end en Neowyze, Full-Stack en Eximo Software
- Stack defendible en entrevista: React, Astro, Tailwind CSS, PHP/Laravel, bases de datos relacionales, Docker (uso casi diario)
- Portfolio online con proyectos propios: Pallet Control, MiColeNQN, MangaVault, CommitGPT
- Trabajo actual fuera del rubro (Carrefour, sector mayorista, turno tarde/noche)

**El problema real (autodiagnóstico):**
No es falta de título. Es falta de **constancia** y de **profundidad**:
- Postulaciones esporádicas en vez de volumen sostenido
- Proyectos que se hacen, se dejan, y se retoman meses después
- Proyectos que "funcionan" pero no están *production-ready*
- Sin contribuciones externas ni code review de gente que sepa más

**La conclusión que ordena todo este plan:**
Una carrera de 5 años no arregla el hábito. Las universidades también se abandonan por falta de constancia. Este roadmap existe para generar **evidencia real** — en 4 meses — de si la constancia es sostenible. Esa evidencia es la que después informa la decisión sobre la licenciatura.

---

## 1. El objetivo de vida (lo que estamos optimizando)

Lo que se busca en un trabajo, en orden:

1. **Sostenible en el tiempo** — no un laburo que se cae en dos años
2. **Mayor ingreso monetario posible** — con el horizonte de comprar casa y auto
3. **Remoto y "liviano"** — metodología ágil, sprints semanales, autonomía sobre el propio ritmo: terminar las tareas y poder relajarse; un día a full, otro más tranquilo

**Por qué esto descarta Ingeniería en Petróleo:**
5 años presenciales, 38 materias, primeros 3 años de básicas duras (Álgebra, Análisis, Física, Química, Geología). El perfil de egresado es de **campo**: perforación, terminación, producción, guardias. Da la seguridad económica buscada, pero es lo opuesto exacto de "remoto y liviano". La atracción es la plata, no la disciplina — y eso es una variable que se puede resolver por otro camino.

---

## 2. La dirección elegida: Full-Stack con foco Backend

**Por qué no front puro:** es el segmento más saturado de juniors. Es donde hoy se está compitiendo sin resultado.

**Por qué backend + DevOps:** hay menos gente que lo domina bien. Es lo que separa al "junior que hace CRUDs" del perfil contratable en remoto con buen sueldo.

**Punto de partida:** el stack actual ya está parado en full-stack, solo que inclinado a front por la experiencia laboral previa. No hay que empezar de cero — hay que **rebalancear y profundizar**.

---

## 3. Principio rector sobre tecnologías

> **No hace falta "otro framework". Hace falta profundizar lo que ya se sabe + sumar UNO nuevo con criterio.**

Los conceptos de backend —autenticación, validación, ORM, diseño de API REST, testing, seguridad— son **los mismos** en Laravel, Node, Django o Java. Solo cambia la sintaxis. Con Laravel ya manejado a diario, el ~80% del conocimiento es transferible.

El error clásico es aprender 5 frameworks superficialmente en vez de dominar uno a fondo.

### Prioridades de tecnología

| Tecnología | Prioridad | Razón |
|---|---|---|
| **Laravel (a fondo)** | 🔴 Alta — Mes 1 | Ya se usa a diario. Falta llevarlo de "funciona" a *production-ready*. |
| **Node.js + TypeScript** | 🔴 Alta — Mes 2-3 | Mismo lenguaje que React. No es sintaxis nueva, es el mismo lenguaje del otro lado. React + Node/TS es **el combo más pedido en vacantes remotas full-stack**. |
| **Python / FastAPI** | 🟡 Media — segunda ola | Ya se está tanteando. Fuerte en mercado internacional y abre puerta a IA/data. Como backend puro compite con Node por el mismo tipo de puesto. No es urgente. |
| **Java** | ⚪ Baja — descartado por ahora | Mundo corporativo/bancario, contratación lenta, cultura presencial-jerárquica. No es el perfil remoto-ágil buscado. |

**Meta de cobertura al final:** React + Node/TS + Laravel. Eso cubre tanto vacantes PHP como el ecosistema JS full-stack, que es el más grande en remoto.

---

## 4. MES 1 — Backend serio (no Laravel de tutorial)

**Objetivo:** agarrar UN proyecto propio existente (no arrancar uno nuevo) y llevarlo a calidad profesional.

### 4.1 Reescritura del backend
- **Autenticación real** — JWT o sesiones bien implementadas, no un login de tutorial
- **Validaciones robustas** — en el servidor, no confiando en el front
- **Manejo de errores consistente** — respuestas de error estandarizadas, no `try/catch` sueltos
- **Tests unitarios y de integración** — que cubran casos límite, no solo el camino feliz

### 4.2 Base de datos bien hecha
- Modelado con **relaciones complejas** (no tres tablas planas)
- **Índices** donde corresponde y entendiendo por qué
- **Migraciones prolijas** y versionadas
- Decisiones de diseño justificadas

### 4.3 El criterio de calidad (qué mira un tech lead)
La diferencia entre "una app del clima" y un proyecto que impresiona **no es la idea, es la profundidad**. Lo que se nota al toque:
- Manejo de estado prolijo
- Arquitectura pensada, no improvisada
- Decisiones justificadas en el README
- Qué pasa cuando la API externa se cae
- Caching inteligente donde tiene sentido
- Documentación escrita como si otro dev fuera a mantenerlo

**Bonus:** un proyecto que resuelve un problema real y específico (tipo Pallet Control o MiColeNQN) vale más que diez clones de todo-list, porque demuestra que se piensa en usuarios reales, no en tutoriales.

---

## 5. MES 2 — DevOps e infraestructura

**Objetivo:** llevar Docker (ya de uso diario) al terreno de infraestructura real y operable.

### 5.1 Contenidos
- **CI/CD real** — GitHub Actions que corra los tests y deployee automáticamente
- **Deploy productivo** — dominio propio, HTTPS, variables de entorno bien manejadas (nunca hardcodeadas)
- **Monitoreo básico** — logs accesibles, algo tipo Sentry o UptimeRobot en plan gratuito

### 5.2 Local vs. mostrar al mundo (dos etapas distintas)

**Etapa 1 — Local (donde se aprende):**
`localhost` + Docker Compose. Perfecto para desarrollo, iteración rápida, costo cero.

**Etapa 2 — Deploy real (donde se muestra):**
Acá sí se necesita hosting sí o sí. Un link de portfolio que diga *"cloná el repo para verlo"* no convence a nadie.

| Opción | Costo aprox. | Qué demuestra |
|---|---|---|
| **PaaS simple** (Vercel, Railway, Render) | Gratis / bajo | Rápido de mostrar, pero poco DevOps |
| **VPS propio** (DigitalOcean, Hetzner) | ~5 USD/mes | Docker, nginx, HTTPS con Let's Encrypt, CI/CD propio. **Demuestra que se sabe operar infraestructura, no solo escribir código.** Salto natural desde Docker. |
| **Dominio propio** (Namecheap u otro) | ~10 USD/año | Un dominio propio pesa mucho más que un `.vercel.app` genérico |

**Estrategia mixta recomendada:** 1-2 proyectos en VPS propio (para mostrar DevOps real) + el resto en PaaS (para no quemar tiempo de infra en proyectos secundarios).

---

## 6. MES 3 — Contribución externa + fundamentos

**Objetivo:** conseguir lo que la facultad da "gratis" y que trabajando solo falta.

### 6.1 Lo que la universidad da y hay que replicar afuera
- Compañeros que empujan a no aflojar
- Deadlines externos innegociables
- **Code review de gente que sabe más** ← esto es lo que más falta laburando solo

### 6.2 Acciones
- **Primer PR mergeado en un repo open source activo** del stack propio (React, Astro, Laravel). Que alguien rechace un PR y explique por qué es oro puro. **Un PR mergeado en proyecto ajeno pesa más en un CV que cinco proyectos personales.**
- **Leer código de proyectos grandes y bien hechos en GitHub** — no para copiar, para entender por qué está estructurado así
- **Fundamentos: estructuras de datos y algoritmos** — no para rendir un examen, para entrevistas técnicas (muchas remotas las piden). Es contenido que la licenciatura daría igual.

---

## 7. MES 4 — Pulir y salir a cazar

### 7.1 Portfolio
- **2-3 proyectos de nivel real**, no 5 mediocres
- Actualizado, con deploys funcionando y READMEs que justifiquen decisiones

### 7.2 Búsqueda activa (acá se ataca la falta de constancia de frente)
- **Volumen definido de postulaciones por semana** — número fijo, no "cuando tenga ganas"
- **LinkedIn activo** — mostrando el progreso mes a mes, compartiendo lo que se construye
- **Networking** — comunidad dev de Neuquén y remota. En un mercado saturado de CVs sin respuesta, un contacto directo o referido pesa mucho más que aplicar a ciegas por portal.

### 7.3 El sistema de hábito (el corazón del plan)
- X postulaciones por semana (número a definir y sostener)
- Un commit por día

---

## 8. El punto de decisión (fin de Mes 4)

Acá se evalúa con **data real, no con sensaciones**:

**¿Mejoró la tasa de respuesta a las postulaciones?**

- **Sí** → el camino es correcto. Se sigue por acá, se profundiza, y la licenciatura pasa a ser opcional/complementaria.
- **No, después de 4 meses de trabajo serio y sostenido** → ahí la licenciatura pesa distinto en la balanza. No como solución mágica, sino como **palanca adicional una vez demostrado que la constancia no era el problema**.

---

## Anexo A — Licenciatura en Ciencias de la Computación (UNCo)

**Datos concretos:**
- Facultad de Informática, Neuquén Capital
- 5 años, presencial
- **Título intermedio a los 3 años: Analista Programador Universitario** ← no es todo o nada

**Materias de primer año:** Elementos de Álgebra, Resolución de Problemas y Algoritmos, Introducción a la Computación, Cálculo Diferencial e Integral, Programación Orientada a Objetos, Estructuras de Datos, Teoría de la Computación I y II, Inglés Técnico I y II, Programación Concurrente, Arquitecturas y Organización de Computadoras, Ingeniería de Requerimientos, Modelado de Datos, Probabilidad y Estadística, Principios de Lenguajes de Programación.

**Qué forma realmente:**
No forma "un programador que arma páginas con React". Forma a alguien que entiende **cómo funcionan las cosas por dentro**: sistemas operativos, compiladores, bases de datos desde cero, redes, teoría de la computación (qué es computable, complejidad algorítmica).

Hoy se trabaja **con herramientas ya hechas** (React, Laravel, Docker). La licenciatura lleva un escalón más abajo: enseña *por qué* esas herramientas funcionan así, y da la base para construir lo que esas herramientas no resuelven — un sistema distribuido, optimización de performance real, arquitectura desde cero en vez de ensamblar piezas.

**Sobre la matemática dura:** Álgebra, Análisis, Probabilidad y Teoría de la Computación no son relleno. Son la herramienta con la que después se razona sobre complejidad, corrección y eficiencia a escala. Es fea de cursar, pero es lo que separa a quien "arma features" de quien puede diseñar el sistema completo.

**¿Forma para manejar equipos?** **No es una carrera de management.** No hay materias de liderazgo de personas ni gestión tipo MBA. El perfil habla de **liderar proyectos de desarrollo de software** — es decir: *tech lead* o arquitecto técnico, no jefe/gerente. Da el peso técnico para ser la referencia del equipo (el que decide arquitectura, el que dice cómo se hace), no el que reparte tickets desde una oficina. El salto a gestión de personas viene después, con años de laburo, no con la currícula.

**Actividades reservadas por título (Res. Ministerial 1254/2018):**
Habilita legalmente para cosas que una tecnicatura o un bootcamp no dan:
1. Especificar, proyectar y desarrollar sistemas de información y software cuya utilización pueda afectar seguridad, salud, bienes o derechos
2. Proyectar y dirigir seguridad informática
3. Establecer métricas y normas de calidad de software
4. Certificar funcionamiento, condición de uso o estado de lo anterior

Eso es peso real para roles senior, sector público, o cualquier lugar donde el título de grado es filtro duro.

**Veredicto:** compatible con la vida buscada (remoto, sprints, autonomía) y con la disciplina ya elegida — no se empieza de cero, se formaliza y profundiza lo que ya se sabe hacer. Destraba roles de más nivel (backend/infra pesado, arquitectura, seguridad, sistemas distribuidos) que pagan mejor y son más remote-friendly que el front junior saturado. **Lo que no resuelve por sí sola es el problema actual: ya hay formación y experiencia real, y aun así no aparece el trabajo.**

---

## Anexo B — Alternativa lateral no explorada

**Instrumentación y automatización en Vaca Muerta.**

El camino de Operador de Instrumentación ya estaba identificado en la búsqueda previa en el sector oil & gas, pero sin usar el perfil de programador. La industria petrolera depende fuerte de SCADA, sensores, IoT industrial, software de monitoreo de pozos y automatización.

Es un cruce real entre tech y oil & gas: sueldos de convenio petrolero, sin necesidad de arrancar de cero una ingeniería de 5 años. **Queda como carta guardada, no como plan principal** — porque no cumple el requisito de "remoto y liviano".

---

*Documento vivo. Meses 2, 3 y 4 pendientes de desarrollo en detalle.*
