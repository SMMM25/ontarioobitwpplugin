
import { useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { toast } from "sonner";
import { scrapeObituaries, DEFAULT_SCRAPER_CONFIG, scrapePreviousMonthObituaries } from "@/utils/scraperUtils";

// Import refactored components
import ScraperConfigPanel from "./scraper/ScraperConfigPanel";
import ScraperActionButtons from "./scraper/ScraperActionButtons";
import SourcesStatusPanel from "./scraper/SourcesStatusPanel";
import ScraperLogView from "./scraper/ScraperLogView";
import TroubleshootingTips from "./scraper/TroubleshootingTips";

const ScraperDebug = () => {
  const [isScrapingNow, setIsScrapingNow] = useState(false);
  const [isHistoricalScraping, setIsHistoricalScraping] = useState(false);
  const [scraperLog, setScraperLog] = useState<Array<{
    timestamp: string;
    type: "info" | "error" | "success" | "warning";
    message: string;
  }>>([]);
  const [scrapedSources, setScrapedSources] = useState<string[]>([]);
  const [connectionErrors, setConnectionErrors] = useState<string[]>([]);
  const [scraperConfig, setScraperConfig] = useState({
    ...DEFAULT_SCRAPER_CONFIG,
  });
  const [useAdaptiveMode, setUseAdaptiveMode] = useState(true);

  const logMessage = (type: "info" | "error" | "success" | "warning", message: string) => {
    const timestamp = new Date().toLocaleTimeString();
    setScraperLog(prev => [...prev, { timestamp, type, message }]);
  };

  const handleTestScrape = async () => {
    setIsScrapingNow(true);
    setScraperLog([]);
    setScrapedSources([]);
    setConnectionErrors([]);
    
    // Log start
    logMessage("info", "Starting enhanced test scrape of obituary sources...");
    logMessage("info", `Using config: ${JSON.stringify({
      regions: scraperConfig.regions,
      maxAge: scraperConfig.maxAge,
      adaptiveMode: useAdaptiveMode,
      retryAttempts: scraperConfig.retryAttempts
    })}`);
    
    try {
      // Set up config with all regions to test comprehensive scraping
      const testConfig = {
        ...scraperConfig,
        adaptiveMode: useAdaptiveMode
      };
      
      // Run the enhanced scraper
      const result = await scrapeObituaries(testConfig);
      
      // Process results
      if (result.success) {
        const foundCount = result.data?.length || 0;
        logMessage("success", `Scraper completed with overall success (found ${foundCount} obituaries)`);
        
        // Track successful regions
        const successfulRegions = scraperConfig.regions.filter(
          region => !result.errors || !result.errors[region]
        );
        setScrapedSources(successfulRegions);
        
        // Track failed regions
        const failedRegions = result.errors ? Object.keys(result.errors) : [];
        setConnectionErrors(failedRegions);
        
        // Log individual errors
        if (result.errors) {
          Object.entries(result.errors).forEach(([region, error]) => {
            logMessage("error", `Region ${region} failed: ${error}`);
          });
        }
        
        toast.success("Scraper test completed", {
          description: `Found ${foundCount} obituaries from ${successfulRegions.length} regions`
        });
      } else {
        logMessage("error", `Scraper failed: ${result.message}`);
        
        // Log specific region errors
        if (result.errors) {
          Object.entries(result.errors).forEach(([region, error]) => {
            logMessage("error", `Region ${region} failed: ${error}`);
            setConnectionErrors(prev => [...prev, region]);
          });
        }
        
        toast.error("Scraper test failed", {
          description: result.message
        });
      }
    } catch (error) {
      logMessage("error", `Test scrape failed with exception: ${error instanceof Error ? error.message : String(error)}`);
      toast.error("Scraper test failed", {
        description: "An unexpected error occurred."
      });
    } finally {
      setIsScrapingNow(false);
    }
  };

  const handleHistoricalScrape = async () => {
    setIsHistoricalScraping(true);
    logMessage("info", "Starting historical data scrape for previous month...");
    
    try {
      // Configure for historical scraping
      const historicalConfig = {
        ...scraperConfig,
        adaptiveMode: useAdaptiveMode,
        retryAttempts: 5, // More retries for historical data
      };
      
      const result = await scrapePreviousMonthObituaries(historicalConfig);
      
      if (result.success) {
        const foundCount = result.data?.length || 0;
        logMessage("success", `Historical scrape completed successfully (found ${foundCount} obituaries)`);
        
        // Record successful/failed regions
        const successfulRegions = scraperConfig.regions.filter(
          region => !result.errors || !result.errors[region]
        );
        const failedRegions = result.errors ? Object.keys(result.errors) : [];
        
        // Log results by region
        successfulRegions.forEach(region => {
          logMessage("success", `Successfully retrieved historical data for ${region}`);
        });
        
        if (result.errors) {
          Object.entries(result.errors).forEach(([region, error]) => {
            logMessage("error", `Failed to retrieve historical data for ${region}: ${error}`);
          });
        }
        
        toast.success("Historical data retrieved", {
          description: `Found ${foundCount} obituaries from previous month`
        });
      } else {
        logMessage("error", `Historical scrape failed: ${result.message}`);
        
        if (result.errors) {
          Object.entries(result.errors).forEach(([region, error]) => {
            logMessage("error", `Region ${region} historical data failed: ${error}`);
          });
        }
        
        toast.error("Historical scrape failed", {
          description: result.message
        });
      }
    } catch (error) {
      logMessage("error", `Historical scrape exception: ${error instanceof Error ? error.message : String(error)}`);
      toast.error("Historical scrape failed", {
        description: "An unexpected error occurred"
      });
    } finally {
      setIsHistoricalScraping(false);
    }
  };

  return (
    <Card className="w-full">
      <CardHeader>
        <CardTitle className="text-xl font-semibold">Enhanced Scraper Diagnostics</CardTitle>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="space-y-2">
          {/* Scraper config panel */}
          <ScraperConfigPanel 
            useAdaptiveMode={useAdaptiveMode}
            setUseAdaptiveMode={setUseAdaptiveMode}
            scraperConfig={scraperConfig}
          />
          
          {/* Action buttons */}
          <ScraperActionButtons 
            isScrapingNow={isScrapingNow}
            isHistoricalScraping={isHistoricalScraping}
            onTestScrape={handleTestScrape}
            onHistoricalScrape={handleHistoricalScrape}
          />
          
          {/* Source status panels */}
          <SourcesStatusPanel 
            scrapedSources={scrapedSources}
            connectionErrors={connectionErrors}
            isLoading={isScrapingNow || isHistoricalScraping}
          />
          
          {/* Separator */}
          <Separator className="my-4" />
          
          {/* Scraper log */}
          <ScraperLogView logs={scraperLog} />
          
          {/* Troubleshooting tips */}
          <TroubleshootingTips />
        </div>
      </CardContent>
    </Card>
  );
};

export default ScraperDebug;
